// Small, dependency-free QR Code generator (ISO/IEC 18004, model 2), byte
// mode only, versions 1–40. Used for the TokenHub APP sign-in link so the
// console never sends the one-time code to a third-party QR service.

const ECC_CODEWORDS_PER_BLOCK = [
  // L
  [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
  // M
  [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
  // Q
  [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
  // H
  [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30]
];

const NUM_ERROR_CORRECTION_BLOCKS = [
  [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
  [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
  [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
  [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81]
];

// error correction level -> [table row, format bits]
const LEVELS = { L: [0, 1], M: [1, 0], Q: [2, 3], H: [3, 2] };

function rawDataModules(version) {
  let result = (16 * version + 128) * version + 64;
  if (version >= 2) {
    const numAlign = Math.floor(version / 7) + 2;
    result -= (25 * numAlign - 10) * numAlign - 55;
    if (version >= 7) {
      result -= 36;
    }
  }
  return result;
}

function dataCodewords(version, row) {
  return Math.floor(rawDataModules(version) / 8) - ECC_CODEWORDS_PER_BLOCK[row][version] * NUM_ERROR_CORRECTION_BLOCKS[row][version];
}

function gfMultiply(x, y) {
  let z = 0;
  for (let i = 7; i >= 0; i--) {
    z = (z << 1) ^ ((z >>> 7) * 0x11d);
    z ^= ((y >>> i) & 1) * x;
  }
  return z & 0xff;
}

function reedSolomonDivisor(degree) {
  const result = new Array(degree).fill(0);
  result[degree - 1] = 1;
  let root = 1;
  for (let i = 0; i < degree; i++) {
    for (let j = 0; j < result.length; j++) {
      result[j] = gfMultiply(result[j], root);
      if (j + 1 < result.length) {
        result[j] ^= result[j + 1];
      }
    }
    root = gfMultiply(root, 0x02);
  }
  return result;
}

function reedSolomonRemainder(data, divisor) {
  const result = divisor.map(() => 0);
  for (const byte of data) {
    const factor = byte ^ result.shift();
    result.push(0);
    divisor.forEach((coefficient, index) => {
      result[index] ^= gfMultiply(coefficient, factor);
    });
  }
  return result;
}

function bit(value, index) {
  return ((value >>> index) & 1) !== 0;
}

function utf8Bytes(text) {
  return Array.from(new TextEncoder().encode(String(text)));
}

/**
 * QR Code modules for `text` as a square boolean matrix (true = dark).
 * @param {string} text
 * @param {{ level?: "L"|"M"|"Q"|"H" }} [options]
 * @returns {boolean[][]}
 */
export function qrMatrix(text, { level = "M" } = {}) {
  const [row, formatBits] = LEVELS[level] || LEVELS.M;
  const bytes = utf8Bytes(text);

  let version = 1;
  for (; version <= 40; version++) {
    const countBits = version <= 9 ? 8 : 16;
    if (bytes.length < 2 ** countBits && 4 + countBits + bytes.length * 8 <= dataCodewords(version, row) * 8) {
      break;
    }
  }
  if (version > 40) {
    throw new RangeError("Text is too long for a QR Code");
  }

  // Segment: byte mode indicator, character count, data.
  const bits = [];
  const push = (value, length) => {
    for (let i = length - 1; i >= 0; i--) {
      bits.push((value >>> i) & 1);
    }
  };
  push(0b0100, 4);
  push(bytes.length, version <= 9 ? 8 : 16);
  bytes.forEach((byte) => push(byte, 8));
  const capacity = dataCodewords(version, row) * 8;
  push(0, Math.min(4, capacity - bits.length));
  push(0, (8 - (bits.length % 8)) % 8);
  for (let pad = 0xec; bits.length < capacity; pad ^= 0xec ^ 0x11) {
    push(pad, 8);
  }
  const data = [];
  for (let i = 0; i < bits.length; i += 8) {
    data.push(bits.slice(i, i + 8).reduce((acc, value) => (acc << 1) | value, 0));
  }

  // Error correction blocks, interleaved.
  const numBlocks = NUM_ERROR_CORRECTION_BLOCKS[row][version];
  const blockEccLength = ECC_CODEWORDS_PER_BLOCK[row][version];
  const rawCodewords = Math.floor(rawDataModules(version) / 8);
  const numShortBlocks = numBlocks - (rawCodewords % numBlocks);
  const shortBlockLength = Math.floor(rawCodewords / numBlocks);
  const divisor = reedSolomonDivisor(blockEccLength);
  const blocks = [];
  for (let i = 0, k = 0; i < numBlocks; i++) {
    const chunk = data.slice(k, k + shortBlockLength - blockEccLength + (i < numShortBlocks ? 0 : 1));
    k += chunk.length;
    const ecc = reedSolomonRemainder(chunk, divisor);
    if (i < numShortBlocks) {
      chunk.push(0);
    }
    blocks.push(chunk.concat(ecc));
  }
  const codewords = [];
  for (let i = 0; i < blocks[0].length; i++) {
    blocks.forEach((block, j) => {
      if (i !== shortBlockLength - blockEccLength || j >= numShortBlocks) {
        codewords.push(block[i]);
      }
    });
  }

  const size = version * 4 + 17;
  const modules = Array.from({ length: size }, () => new Array(size).fill(false));
  const reserved = Array.from({ length: size }, () => new Array(size).fill(false));
  const setFunction = (x, y, dark) => {
    modules[y][x] = dark;
    reserved[y][x] = true;
  };

  // Timing patterns.
  for (let i = 0; i < size; i++) {
    setFunction(6, i, i % 2 === 0);
    setFunction(i, 6, i % 2 === 0);
  }
  // Finder patterns with separators.
  const finder = (cx, cy) => {
    for (let dy = -4; dy <= 4; dy++) {
      for (let dx = -4; dx <= 4; dx++) {
        const distance = Math.max(Math.abs(dx), Math.abs(dy));
        const x = cx + dx;
        const y = cy + dy;
        if (x >= 0 && x < size && y >= 0 && y < size) {
          setFunction(x, y, distance !== 2 && distance !== 4);
        }
      }
    }
  };
  finder(3, 3);
  finder(size - 4, 3);
  finder(3, size - 4);
  // Alignment patterns.
  if (version > 1) {
    const numAlign = Math.floor(version / 7) + 2;
    const step = version === 32 ? 26 : Math.ceil((version * 4 + 4) / (numAlign * 2 - 2)) * 2;
    const positions = [6];
    for (let pos = size - 7; positions.length < numAlign; pos -= step) {
      positions.splice(1, 0, pos);
    }
    positions.forEach((py, i) => {
      positions.forEach((px, j) => {
        if ((i === 0 && j === 0) || (i === 0 && j === numAlign - 1) || (i === numAlign - 1 && j === 0)) {
          return;
        }
        for (let dy = -2; dy <= 2; dy++) {
          for (let dx = -2; dx <= 2; dx++) {
            setFunction(px + dx, py + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
          }
        }
      });
    });
  }
  const drawFormat = (mask) => {
    const value = (formatBits << 3) | mask;
    let remainder = value;
    for (let i = 0; i < 10; i++) {
      remainder = (remainder << 1) ^ ((remainder >>> 9) * 0x537);
    }
    const format = ((value << 10) | remainder) ^ 0x5412;
    for (let i = 0; i <= 5; i++) {
      setFunction(8, i, bit(format, i));
    }
    setFunction(8, 7, bit(format, 6));
    setFunction(8, 8, bit(format, 7));
    setFunction(7, 8, bit(format, 8));
    for (let i = 9; i < 15; i++) {
      setFunction(14 - i, 8, bit(format, i));
    }
    for (let i = 0; i < 8; i++) {
      setFunction(size - 1 - i, 8, bit(format, i));
    }
    for (let i = 8; i < 15; i++) {
      setFunction(8, size - 15 + i, bit(format, i));
    }
    setFunction(8, size - 8, true);
  };
  drawFormat(0);
  if (version >= 7) {
    let remainder = version;
    for (let i = 0; i < 12; i++) {
      remainder = (remainder << 1) ^ ((remainder >>> 11) * 0x1f25);
    }
    const versionBits = (version << 12) | remainder;
    for (let i = 0; i < 18; i++) {
      const dark = bit(versionBits, i);
      const a = size - 11 + (i % 3);
      const b = Math.floor(i / 3);
      setFunction(a, b, dark);
      setFunction(b, a, dark);
    }
  }

  // Data in the zig-zag order.
  let index = 0;
  for (let right = size - 1; right >= 1; right -= 2) {
    if (right === 6) {
      right = 5;
    }
    for (let vertical = 0; vertical < size; vertical++) {
      for (let j = 0; j < 2; j++) {
        const x = right - j;
        const upward = ((right + 1) & 2) === 0;
        const y = upward ? size - 1 - vertical : vertical;
        if (!reserved[y][x] && index < codewords.length * 8) {
          modules[y][x] = bit(codewords[index >>> 3], 7 - (index & 7));
          index++;
        }
      }
    }
  }

  const masked = (mask, x, y) => {
    switch (mask) {
      case 0: return (x + y) % 2 === 0;
      case 1: return y % 2 === 0;
      case 2: return x % 3 === 0;
      case 3: return (x + y) % 3 === 0;
      case 4: return (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0;
      case 5: return ((x * y) % 2) + ((x * y) % 3) === 0;
      case 6: return (((x * y) % 2) + ((x * y) % 3)) % 2 === 0;
      default: return (((x + y) % 2) + ((x * y) % 3)) % 2 === 0;
    }
  };
  const applyMask = (mask) => {
    for (let y = 0; y < size; y++) {
      for (let x = 0; x < size; x++) {
        if (!reserved[y][x] && masked(mask, x, y)) {
          modules[y][x] = !modules[y][x];
        }
      }
    }
  };

  let bestMask = 0;
  let bestPenalty = Infinity;
  for (let mask = 0; mask < 8; mask++) {
    applyMask(mask);
    drawFormat(mask);
    const score = penalty(modules);
    if (score < bestPenalty) {
      bestPenalty = score;
      bestMask = mask;
    }
    applyMask(mask); // XOR again to undo
  }
  applyMask(bestMask);
  drawFormat(bestMask);

  return modules;
}

function penalty(modules) {
  const size = modules.length;
  let score = 0;
  const line = (get) => {
    let run = 1;
    for (let i = 1; i <= size; i++) {
      if (i < size && get(i) === get(i - 1)) {
        run++;
      } else {
        if (run >= 5) {
          score += run - 2;
        }
        run = 1;
      }
    }
    // Finder-like 1:1:3:1:1 pattern with four light modules on either side.
    const pattern = [true, false, true, true, true, false, true];
    for (let i = 0; i + 7 <= size; i++) {
      if (pattern.every((value, k) => get(i + k) === value)) {
        const before = [1, 2, 3, 4].every((k) => i - k < 0 || !get(i - k));
        const after = [7, 8, 9, 10].every((k) => i + k >= size || !get(i + k));
        if (before || after) {
          score += 40;
        }
      }
    }
  };
  let dark = 0;
  for (let y = 0; y < size; y++) {
    line((x) => modules[y][x]);
    line((x) => modules[x][y]);
    for (let x = 0; x < size; x++) {
      if (modules[y][x]) {
        dark++;
      }
      if (x + 1 < size && y + 1 < size) {
        const color = modules[y][x];
        if (color === modules[y][x + 1] && color === modules[y + 1][x] && color === modules[y + 1][x + 1]) {
          score += 3;
        }
      }
    }
  }
  const total = size * size;
  score += Math.floor(Math.abs(dark * 20 - total * 10) / total) * 10;
  return score;
}

/**
 * QR Code as an <svg> element built with DOM APIs (dark modules as one path).
 * @param {string} text
 * @param {{ level?: "L"|"M"|"Q"|"H", border?: number, label?: string }} [options]
 * @returns {SVGSVGElement}
 */
export function qrSvgElement(text, { level = "M", border = 4, label = "" } = {}) {
  const modules = qrMatrix(text, { level });
  const size = modules.length + border * 2;
  const ns = "http://www.w3.org/2000/svg";
  const svg = document.createElementNS(ns, "svg");
  svg.setAttribute("viewBox", `0 0 ${size} ${size}`);
  svg.setAttribute("shape-rendering", "crispEdges");
  svg.setAttribute("role", "img");
  if (label) {
    svg.setAttribute("aria-label", label);
  }
  const background = document.createElementNS(ns, "rect");
  background.setAttribute("width", String(size));
  background.setAttribute("height", String(size));
  background.setAttribute("fill", "#ffffff");
  const parts = [];
  modules.forEach((rowModules, y) => {
    rowModules.forEach((dark, x) => {
      if (dark) {
        parts.push(`M${x + border},${y + border}h1v1h-1z`);
      }
    });
  });
  const path = document.createElementNS(ns, "path");
  path.setAttribute("d", parts.join(""));
  path.setAttribute("fill", "#000000");
  svg.append(background, path);
  return svg;
}

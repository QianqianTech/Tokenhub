# TokenHub 品牌标识

完整规范见 `tokenhub-brand-guidelines.html`（浏览器直接打开）。

## 标志

曜石黑圆角底板 + 信号绿字母 A + 感叹号（深松绿竖条、信号绿圆点）。
原始设计稿：`source/tokenhub-master-250.png`，矢量文件按其 1:1 重建（250 u 网格）。

| 色名 | HEX | 用途 |
| --- | --- | --- |
| 曜石黑 Obsidian | `#151A21` | 底板、浅色背景上的字标 |
| 信号绿 Signal Green | `#34D399` | 字母 A、感叹号圆点 |
| 深松绿 Pine | `#236D57` | 感叹号竖条（信号绿 45% 叠在曜石黑上） |
| 纸白 Paper | `#FFFFFF` | 深色背景上的字标 |

## 目录

- `logo/svg/` 矢量母版：图标、横版 / 竖版组合、无底板图形、字标及单色版
- `logo/png/` 位图导出
- `favicon/` favicon.ico（16/32/48）、favicon.svg、apple-touch-icon、PWA 图标、site.webmanifest
- `source/` 生成脚本

## 使用要点

- 安全区 X = 图标高度的 1/4；横版最小 24 px 高，图标 16 px 时用 `tokenhub-favicon-16.svg`。
- 浅色背景用 `tokenhub-logo-horizontal.svg`，深色背景用 `-reverse`。
- 不得拉伸、旋转、改色、加效果、改底板圆角，也不得把感叹号竖条改成亮绿。

## 重新生成

```sh
cd brand/source
python3 gen_text.py /path/to/InterDisplay-Bold.otf TokenHub -10 > text.json   # 仅在修改字标时需要
python3 build.py      # SVG
python3 export.py     # PNG / ICO / manifest（需要 node + playwright、ImageMagick）
python3 guide.py      # 规范页
```

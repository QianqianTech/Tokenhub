const statusNode = document.querySelector('#status');

async function refreshHealth() {
  try {
    const response = await fetch('/admin-api/health', {
      headers: {
        Accept: 'application/json',
      },
    });
    const payload = await response.json();
    statusNode.textContent = payload.ok ? '服务运行正常。' : '服务状态异常。';
  } catch {
    statusNode.textContent = '无法访问健康检查接口。';
  }
}

refreshHealth();

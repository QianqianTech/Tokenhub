# 管理后台浏览器测试

在真实 Chromium 中运行管理后台，并以内存版 `mock-server.mjs` 模拟 `/admin-api`，不依赖 MySQL / Redis。

```sh
cd tests/e2e
npm install            # 首次：安装 playwright
npx playwright install chromium
npm test               # 运行全部 *.test.mjs
npm run mock           # 手动调试：http://127.0.0.1:8090/admin/  账号 admin / admin-pass
```

- `smoke.test.mjs`：遍历所有菜单页，断言无运行时错误与空白页。
- 新增后端接口时，同步在 `mock-server.mjs` 中补充对应的模拟响应。

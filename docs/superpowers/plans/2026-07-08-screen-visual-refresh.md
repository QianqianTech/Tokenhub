# TokenHub 大屏视觉优化计划

1. 增加前端测试断言，覆盖大屏结构、渲染函数、中文指标标签和无外链约束。
2. 重写 `/screen/index.html`，修复乱码并加入 stage、hero、toolbar、clock、status、grid 等结构。
3. 重构 `/screen/assets/screen.js`，保留原 API，新增指标卡、趋势条、排行、安全和性能的 DOM 渲染。
4. 重写 `/screen/assets/screen.css`，用纯本地 CSS 完成现代大屏视觉。
5. 运行测试与静态扫描，确认没有外部资源、控制台输出、浏览器存储或 `innerHTML` 动态写入。

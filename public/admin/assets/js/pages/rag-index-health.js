// RAG index health page (RAG-043): coverage, stale vectors, failed jobs,
// lexical index status, vector-store reconciliation.
import { adminErrorMessage, api } from "../api.js";
import { button, clear, el, statusMessage } from "../dom.js";

const LEXICAL = { ready: "已就绪", building: "构建中", none: "未构建（关键词检索走有限扫描）" };

function stat(label, value, tone = "") {
  return el("div", { className: `rag-health-stat ${tone}` }, [el("strong", { text: String(value) }), el("span", { text: label })]);
}

export async function renderRagIndexHealthPage(content) {
  clear(content);
  const status = el("div");
  const body = el("div");
  const panel = el("section", { className: "panel rag-index-health" }, [
    el("h2", { text: "索引健康" }),
    el("p", { className: "muted", text: "数据库是唯一可信来源；向量库中多出的点（孤儿）和缺失的点可以在这里核对并修复。定时任务每天自动核对一次。" }),
    status,
    body
  ]);
  content.append(panel);

  const reconcile = async (datasetId, fix) => {
    clear(status).append(statusMessage(fix ? "正在核对并修复…" : "正在核对…"));
    try {
      const { data } = await api("/admin-api/rag/index-reconcile", { method: "POST", body: JSON.stringify({ dataset_id: datasetId || null, fix }) });
      const failed = data.collections.filter((c) => c.error);
      clear(status).append(statusMessage(`核对完成：孤儿点 ${data.orphans}，缺失点 ${data.missing}${fix ? "（已修复，缺失项已重新排队向量化）" : ""}${failed.length ? `；${failed.length} 个集合无法访问` : ""}`, failed.length ? "error" : "success"));
      await load();
    } catch (error) {
      clear(status).append(statusMessage(adminErrorMessage(error), "error"));
    }
  };
  const backfill = async (id) => {
    clear(status).append(statusMessage("正在构建关键词索引…"));
    try {
      const { data } = await api(`/admin-api/rag/datasets/${id}/backfill-lexical`, { method: "POST", body: "{}" });
      clear(status).append(statusMessage(data.skipped ? `已跳过：${data.skipped}` : `完成：${data.chunks} 个分块，${data.points} 个索引点`, "success"));
      await load();
    } catch (error) {
      clear(status).append(statusMessage(adminErrorMessage(error), "error"));
    }
  };

  const load = async () => {
    clear(body).append(statusMessage("正在加载…"));
    try {
      const { data } = await api("/admin-api/rag/index-health");
      const t = data.totals;
      clear(body);
      body.append(el("div", { className: "rag-health-stats" }, [
        stat("数据集", t.datasets),
        stat("有效分块", t.active_chunks),
        stat("覆盖率", `${t.coverage_percent}%`, t.coverage_percent < 99 ? "warn" : ""),
        stat("陈旧向量", t.stale_vectors, t.stale_vectors > 0 ? "warn" : ""),
        stat("失败任务", t.failed_jobs, t.failed_jobs > 0 ? "error" : ""),
        stat("待建关键词索引", t.lexical_pending, t.lexical_pending > 0 ? "warn" : ""),
        stat("未绑定模型的向量配置", data.unbound_vector_configs, data.unbound_vector_configs > 0 ? "error" : "")
      ]));
      const last = data.last_reconcile;
      body.append(el("div", { className: "toolbar-actions" }, [
        el("span", { className: "muted", text: last ? `上次核对：${last.finished_at}，孤儿 ${last.orphans}，缺失 ${last.missing}` : "尚未核对" }),
        button("核对全部", () => reconcile(null, false), "secondary"),
        button("核对并修复全部", () => reconcile(null, true), "primary")
      ]));
      body.append(el("div", { className: "resource-table-wrap" }, el("table", { className: "resource-table" }, [
        el("thead", {}, el("tr", {}, ["知识库 / 数据集", "向量库", "模型", "覆盖率", "陈旧", "失败", "进行中", "关键词索引", "最近索引", ""].map((h) => el("th", { text: h })))),
        el("tbody", {}, data.datasets.map((ds) => el("tr", {}, [
          el("td", { text: `${ds.kb_name} / ${ds.name}` }),
          el("td", { text: ds.vector_store }),
          el("td", { text: ds.model_bound ? ds.embedding_model : "未绑定模型", className: ds.model_bound ? "" : "error" }),
          el("td", { text: `${ds.coverage_percent}%（${ds.indexed_chunks}/${ds.active_chunks}）` }),
          el("td", { text: String(ds.stale_vectors) }),
          el("td", { text: String(ds.failed_jobs) }),
          el("td", { text: String(ds.active_jobs) }),
          el("td", { text: LEXICAL[ds.lexical_index_status] || ds.lexical_index_status }),
          el("td", { text: ds.last_indexed_at || "—" }),
          el("td", {}, [
            button("核对", () => reconcile(ds.id, true), "ghost"),
            ds.vector_store === "qdrant" && ds.lexical_index_status !== "ready" ? button("构建关键词索引", () => backfill(ds.id), "ghost") : null
          ])
        ])))
      ])));
    } catch (error) {
      clear(body).append(statusMessage(adminErrorMessage(error), "error"));
    }
  };
  await load();
}

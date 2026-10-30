import { adminErrorMessage, api } from "../api.js";
import { button, clear, el, field, readFields, statusMessage } from "../dom.js";
import { ragNavigate } from "../lib/navigation.js";
import { renderDataDetails, setErrorOutput, setOperationOutput } from "../lib/output.js";
import { lookupRows } from "../lib/references.js";
import { readFileAsText } from "./rag-upload.js";
import { ragMetricCard, ragSummaryTable } from "./rag.js";
import { valueLabel } from "../resources.js";

export async function renderRagEvaluationPage(content) {
  clear(content);
  const host = el("section", { className: "panel rag-evaluation" }, [
    el("h2", { text: "RAG 检索评估" }),
    statusMessage("正在加载 RAG 检索评估...")
  ]);
  // RAG-041: answer-level evaluation through the judge model (RAG_JUDGE_MODEL_ID), sampled.
  const judgeToggle = el("input", { type: "checkbox", name: "judge" });
  const run = async () => {
    try {
      const result = await api("/admin-api/rag/evaluation-runs", { method: "POST", body: JSON.stringify({ top_k_values: [1, 5, 10, 20], ...(judgeToggle.checked ? { judge: true } : {}) }) });
      await refresh();
    } catch (error) {
      host.replaceChildren(statusMessage(adminErrorMessage(error, "RAG 检索评估运行失败"), "error"));
    }
  };
  const refresh = async () => {
    try {
      const result = await api("/admin-api/rag/evaluation-summary", { query: { window_hours: 24 } });
      const data = result.data || {};
      const source = data.source || "rag_eval_runs";
      const latest = data.latest_run || {};
      const activeCases = Number(data.active_cases || 0);
      const caseCount = Number(latest.case_count || 0);
      const failedCases = Number(latest.failed_cases || 0);
      const qualityStatus = caseCount > 0 && failedCases === caseCount ? "运行完成，质量未达标" : "运行完成";
      const runMetrics = runMetadataMetrics(latest);
      const answer = runMetrics.answer_evaluation || null;
      const percent = (value) => (value === null || value === undefined ? "—" : `${value}%`);
      const score = (value) => (value === null || value === undefined ? "—" : Number(value).toFixed(2));
      host.replaceChildren(...[
        el("header", { className: "table-toolbar" }, [
          el("h2", { text: "RAG 检索评估" }),
          el("div", { className: "toolbar-actions" }, [
            button("创建评估样本", () => ragNavigate("rag-quality", { tab: "rag-eval-cases" }), "secondary"),
            el("label", { className: "rag-eval-judge-toggle", title: "用评审模型抽样评估答案的忠实度与引用正确率（需配置 RAG_JUDGE_MODEL_ID）" }, [judgeToggle, el("span", { text: " 答案级评测" })]),
            button("运行评估", run, "primary", { disabled: activeCases === 0 })
          ])
        ]),
        el("p", { className: "notice", text: activeCases === 0 ? "暂无启用评估样本，请先创建并启用样本。" : `数据来源：${source}，启用样本 ${activeCases}，草稿样本 ${data.draft_cases || 0}` }),
        latest.run_id ? el("p", { className: failedCases > 0 ? "notice error" : "notice", text: qualityStatus }) : null,
        el("div", { className: "rag-dashboard-cards" }, [
          ragMetricCard("Recall@5", latest.recall_at_5 || 0),
          ragMetricCard("Recall@10", latest.recall_at_10 || 0),
          ragMetricCard("Recall@20", latest.recall_at_20 || 0),
          ragMetricCard("MRR", latest.mrr || 0),
          ragMetricCard("Cases", latest.case_count || 0),
          ragMetricCard("Failures", latest.failed_cases || 0, Number(latest.failed_cases || 0) > 0 ? "warning" : ""),
          runMetrics.ndcg_at_10 !== undefined ? ragMetricCard("nDCG@10", score(runMetrics.ndcg_at_10)) : null,
          runMetrics.no_result_rate !== undefined ? ragMetricCard("无结果率", percent(runMetrics.no_result_rate), Number(runMetrics.no_result_rate) > 0 ? "warning" : "") : null,
          runMetrics.low_confidence_rate !== undefined ? ragMetricCard("低置信率", percent(runMetrics.low_confidence_rate), Number(runMetrics.low_confidence_rate) > 0 ? "warning" : "") : null
        ].filter(Boolean)),
        answer ? el("div", { className: "rag-dashboard-cards rag-answer-evaluation" }, [
          ragMetricCard("答案忠实度", score(answer.answer_faithfulness)),
          ragMetricCard("答案相关性", score(answer.answer_relevance)),
          ragMetricCard("引用正确率", score(answer.citation_accuracy)),
          ragMetricCard("引用命中率", percent(answer.citation_hit_rate)),
          ragMetricCard("评审样本", answer.judged_cases ?? 0),
          ragMetricCard("评审失败", answer.judge_failures ?? 0, Number(answer.judge_failures || 0) > 0 ? "warning" : "")
        ]) : null,
        renderDataDetails(data),
        ragSummaryTable("最近评估", data.latest_runs || [], ["run_id", "case_count", "recall_at_1", "recall_at_5", "recall_at_10", "recall_at_20", "mrr", "failed_cases", "created_at"]),
        renderRagEvaluationFailureCards(data.recent_failures || [], refresh)
      ].filter(Boolean));
    } catch (error) {
      host.replaceChildren(statusMessage(adminErrorMessage(error, "RAG 检索评估加载失败"), "error"));
    }
  };
  content.append(host);
  await refresh();
}

function runMetadataMetrics(run) {
  let metadata = run?.metadata;
  if (typeof metadata === "string") {
    try { metadata = JSON.parse(metadata); } catch { metadata = null; }
  }
  return metadata && typeof metadata.metrics === "object" && metadata.metrics ? metadata.metrics : {};
}

function renderRagEvaluationFailureCards(failures, refresh) {
  const rows = Array.isArray(failures) ? failures : [];
  return el("section", { className: "rag-evaluation-failures" }, [
    el("h3", { text: "失败样本与纠偏" }),
    ...(rows.length > 0 ? rows.map((failure) => {
      const runId = String(failure.run_id || "");
      const caseId = String(failure.case_id || "");
      return el("article", { className: "rag-evaluation-failure" }, [
        el("strong", { text: String(failure.query || "未命名查询") }),
        el("p", { text: failure.hit_rank === null || failure.hit_rank === undefined ? "未命中期望内容" : `命中排序：${failure.hit_rank}` }),
        button("转为纠偏任务", async () => {
          await api(`/admin-api/rag/eval-runs/${encodeURIComponent(runId)}/failures/${encodeURIComponent(caseId)}/correction`, { method: "POST" });
          await refresh();
        }, "secondary", { disabled: !runId || !caseId })
      ]);
    }) : [statusMessage("暂无失败样本。")])
  ]);
}

export async function renderRagEvaluationCasesPage(content, initialFilters = {}) {
  clear(content);
  const [datasets, cases] = await Promise.all([
    lookupRows("rag-vector-datasets"),
    lookupRows("rag-eval-cases")
  ]);
  const datasetOptions = datasets.map((dataset) => [dataset.dataset_id, dataset.name || dataset.dataset_id]);
  const initialExpected = Array.isArray(initialFilters.expected_chunk_ids) ? initialFilters.expected_chunk_ids.join(", ") : "";
  const form = el("form", { className: "edit-form rag-evaluation-case-form", onSubmit: (event) => event.preventDefault() });
  const output = el("p", { className: "notice", hidden: true });
  const importInput = el("input", { type: "file", accept: ".json,.jsonl,application/json" });
  const refresh = async () => renderRagEvaluationCasesPage(content, initialFilters);

  form.append(
    field({ name: "dataset_id", label: "评估数据集", type: "select", options: datasetOptions }, String(initialFilters.dataset_id || "")),
    field({ name: "query", label: "检索问题", type: "textarea" }, String(initialFilters.query || "")),
    field({ name: "expected_chunk_ids", label: "期望命中的内容片段", type: "textarea" }, initialExpected),
    field({ name: "expected_answer", label: "参考答案（可选，供答案级评测）", type: "textarea" }, ""),
    field({ name: "status", label: "创建后状态", type: "select", options: [["draft", "草稿"], ["active", "启用"]] }, "draft"),
    el("p", { className: "field-hint", text: "从检索试验台加入样本会自动填写期望片段；手工创建时多个片段以逗号分隔。" }),
    el("div", { className: "toolbar-actions" }, [
      button("创建评估样本", async () => {
        const values = readFields(form, [{ name: "dataset_id" }, { name: "query" }, { name: "expected_chunk_ids" }, { name: "expected_answer" }, { name: "status" }]);
        try {
          const result = await api("/admin-api/rag/eval-cases", {
            method: "POST",
            body: JSON.stringify({ ...values, expected_chunk_ids: String(values.expected_chunk_ids || "").split(/[，,\n]/).map((item) => item.trim()).filter(Boolean) })
          });
          setOperationOutput(output, result.data, "评估样本已创建");
          await refresh();
        } catch (error) {
          setErrorOutput(output, adminErrorMessage(error, "评估样本创建失败"));
        }
      }, "primary", { disabled: datasetOptions.length === 0 })
    ]),
    output
  );

  const importPanel = el("section", { className: "rag-evaluation-bulk-import" }, [
    el("h3", { text: "批量导入样本" }),
    el("p", { text: "上传 JSON 或 JSONL 文件。每行需包含 dataset_id、query、expected_chunk_ids，可选 status。" }),
    importInput,
    button("批量导入样本", async () => {
      const file = importInput.files?.[0];
      if (!file) {
        setErrorOutput(output, "请选择 JSON 或 JSONL 样本文件");
        return;
      }
      try {
        const text = await readFileAsText(file);
        const trimmed = text.trim();
        const rows = trimmed.startsWith("[") ? JSON.parse(trimmed) : trimmed.split(/\r?\n/).filter(Boolean).map((line) => JSON.parse(line));
        const result = await api("/admin-api/rag/eval-cases/import", { method: "POST", body: JSON.stringify({ rows }) });
        setOperationOutput(output, result.data, "样本导入已完成");
        await refresh();
      } catch (error) {
        setErrorOutput(output, adminErrorMessage(error, "样本导入失败，请检查文件格式"));
      }
    }, "secondary")
  ]);

  const cards = cases.map((item) => el("article", { className: "rag-evaluation-case-card" }, [
    el("strong", { text: String(item.query || "未命名问题") }),
    el("p", { text: `状态：${valueLabel("status", item.status) || item.status || "草稿"}` }),
    el("div", { className: "toolbar-actions" }, [
      item.status !== "active" ? button("启用", async () => {
        await api(`/admin-api/rag/eval-cases/${encodeURIComponent(item.case_id)}/status`, { method: "PATCH", body: JSON.stringify({ status: "active" }) });
        await refresh();
      }, "primary") : null,
      item.status === "active" ? button("停用", async () => {
        await api(`/admin-api/rag/eval-cases/${encodeURIComponent(item.case_id)}/status`, { method: "PATCH", body: JSON.stringify({ status: "disabled" }) });
        await refresh();
      }, "secondary") : null
    ].filter(Boolean))
  ]));

  content.append(el("section", { className: "panel rag-evaluation-cases" }, [
    el("header", { className: "table-toolbar" }, [el("h2", { text: "评估样本" }), el("span", { text: `已有 ${cases.length} 个样本` })]),
    form,
    importPanel,
    el("section", { className: "rag-evaluation-case-list" }, cards.length > 0 ? cards : [statusMessage("暂无评估样本。先从检索试验台创建第一条可验证样本。")])
  ]));
}

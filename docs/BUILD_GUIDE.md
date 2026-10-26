# Build Guide: A "Chat With Your PDFs" Tool That Never Guesses

Everything you need to build the same tool: a free-only, local-first chatbot that answers **only from PDFs you upload**, cites the exact page for every statement, and says "I couldn't find this in your PDFs" when the documents don't contain the answer.

You can follow this by hand or hand it to an AI coding assistant, one phase at a time (see section 14).

---

## 1. What you are building

| You want | How the tool does it |
|---|---|
| Answers like ChatGPT, but only from your PDFs | Retrieve passages, give them to an LLM, force it to answer in strict JSON with citations, then **verify every claim in code** |
| "The PDFs don't say" instead of guessing | The model may answer `insufficient_evidence`; the validator also drops any claim it cannot verify |
| Works on tables, charts, photos | Layout-aware parsing (Docling), table rows as their own chunks, optional vision model for figures |
| Free | Local parser, local embeddings, local vector DB, local reranker. LLM = Gemini free tier or a local Ollama model |
| Private by default | Nothing leaves your machine unless you switch hosted mode on yourself |

**Core principle:** the LLM is never trusted. It proposes; deterministic code checks.

---

## 2. Stack

**Backend** (Python 3.11+, I used 3.12)
- FastAPI + Uvicorn, Pydantic v2, pydantic-settings
- SQLite (metadata, chunks, chat history, keyword index via FTS5)
- ChromaDB (local persistent vector store)
- Docling (PDF parsing: layout, tables, figures, captions)
- sentence-transformers: `BAAI/bge-small-en-v1.5` (embeddings) and `cross-encoder/ms-marco-MiniLM-L-6-v2` (reranker)
- pypdfium2 + Pillow (page images and figure crops)
- httpx (Gemini REST calls, no SDK needed)
- Dev: pytest, ruff, mypy (strict), fpdf2

**Frontend**
- Vite + React 19 + TypeScript (strict), Tailwind CSS 4 (`@tailwindcss/vite`), lucide-react icons
- Node 22 (use nvm)

**LLM options (pick one)**
- `none`: ingestion and search still work, chat shows matching passages only
- `gemini`: free tier, hosted. Needs an API key and two explicit opt-in flags
- `ollama`: fully local; download a model first

---

## 3. Repository layout

```
advance-rag-chatbot/
  backend/
    pyproject.toml
    app/
      main.py                 # app factory, router wiring, /health
      config.py               # Settings (all env vars)
      db.py                   # SQLite schema + migrations
      security.py             # optional access token, security headers
      preview.py              # render page / crop figure to PNG
      vision_service.py       # read an image with a vision model
      api/                    # documents, search, answer, chat, previews, vision
      ingestion/              # parser, normalize, chunker, figures, acronyms, describe, pipeline
      index/                  # embedder, vectors (Chroma), lexical (FTS5), indexer, loaders
      search/                 # hybrid (RRF + rerank), expand (linked evidence), multidoc
      knowledge/              # facts + evidence-backed edges between passages
      answer/                 # intent, evidence, prompt, schema, validate, calc, conflicts, smalltalk, service
      providers/              # base (protocols), gemini, ollama, factory
      evaluation/             # evaluation runner and metrics
    tests/
  frontend/
    vite.config.ts            # proxies /api -> http://localhost:8000
    src/                      # App, Sidebar, ChatView, Composer, AnswerBody, SourcePanel, LibraryView, SearchView, api.ts
  evals/                      # question sets + thresholds (jsonl/json)
  docs/
  .env.example
```

---

## 4. Setup

```bash
# backend
cd backend
python3.12 -m venv .venv
.venv/bin/pip install -e ".[dev]"
cp ../.env.example .env            # then edit; never commit .env
.venv/bin/uvicorn app.main:app --port 8000

# frontend (second terminal)
cd frontend
nvm use 22
npm install
npm run dev                        # open the printed localhost URL
```

First run downloads the embedding model (~130 MB) and the reranker (~90 MB). Docling downloads its layout models on first parse.

### `.env` essentials

```
DATA_DIR=./data
DATABASE_URL=sqlite:///./data/rag.sqlite
CHROMA_DIR=./data/chroma

# LLM: choose ONE
LLM_PROVIDER=none                  # none | gemini | ollama

# Gemini free tier needs ALL of these:
#   LLM_PROVIDER=gemini  ALLOW_HOSTED_LLM=true  GEMINI_ENABLED=true  GOOGLE_API_KEY=...  GEMINI_MODEL=...
ALLOW_HOSTED_LLM=false
GEMINI_ENABLED=false
GOOGLE_API_KEY=
GEMINI_MODEL=

OLLAMA_BASE_URL=http://localhost:11434
OLLAMA_MODEL=

RERANKER_ENABLED=true
RETRIEVAL_TOP_K=12
RELATION_EXPANSION_DEPTH=2
MAX_EXPANDED_EVIDENCE=30
MIN_RELATION_CONFIDENCE=0.75

DESCRIBE_FIGURES=false             # describe images at upload with Gemini (sends images to Google)
DESCRIBE_FIGURES_MAX=20
OCR_ENABLED=false
MAX_UPLOAD_MB=100
TELEMETRY_ENABLED=false
LOG_DOCUMENT_TEXT=false
```

Gemini model names get retired. Run `python -m app.providers.gemini_models` (a small script that lists models your key can call) and pick a free one. Google's error text is the final word, so surface it in your UI.

---

## 5. How it works (the pipeline)

### 5.1 Ingestion (upload)

1. Save the PDF; hash it so duplicates are detected.
2. **Parse with Docling** (layout, reading order, tables, figures, captions). Store the raw JSON so reindexing doesn't re-parse (minutes vs. seconds).
3. **Normalize** into your own records: pages, blocks (type, text, page, bbox, heading path), tables (headers, rows, footnotes), figures (title, caption, bbox, units, labels). Report warnings and page coverage; never silently drop content.
4. **Chunk by structure**, not by character count:
   - text under a heading, never crossing a page or heading
   - each table whole (header repeated if split), **plus one chunk per row**: `Row label: header=value; header=value`
   - figures as one chunk: caption, title, axis labels, units
   - captions and footnotes folded into their table or figure
   - acronym expansions ("LIC = low-income country") attached as context
5. **Index** each chunk three ways: SQLite row, FTS5 keyword index, Chroma embedding.
6. **Knowledge layer**: extract cue-based facts (rules, conditions, exceptions, definitions) and typed, evidence-backed edges between chunks (`except_when`, `references`, `limited_by`, ...). Only explicit or high-confidence links count.
7. Optional: describe each real figure with a vision model and append that text to the figure chunk, so images become searchable by what they show.

### 5.2 Retrieval (per question)

1. Classify intent cheaply (lookup, numeric, comparison, summary, chart, eligibility). No LLM.
2. Follow-up handling, deterministic: if the question is short or starts with "and / what about / how about", search with the previous user question plus this one. Also carry forward the passages the previous answer cited. Only earlier **questions** and cited **document text** are reused, never earlier model answers.
3. **Hybrid search**: BM25 (FTS5) + vector search, fused with Reciprocal Rank Fusion (k=60), small boosts for heading match and tables on numeric questions.
4. **Rerank** the top ~30 with the local cross-encoder; drop near-duplicates; cap near-identical table rows.
5. **Expand linked evidence** along the knowledge edges (bounded depth, with a recorded reason), and look for counterevidence (exceptions, restrictions).
6. For comparisons across documents, search each document separately and balance the results.

### 5.3 Answering

1. Build an **evidence packet**: numbered passages `E1..En`, each with document, page, heading, text.
2. Prompt the model with strict rules (section 6) and ask for **JSON only**.
3. Parse JSON strictly (tolerate only a trailing comma slip).
4. **Validate** every claim in code (section 7).
5. Run calculations in Python, not in the model.
6. Return a response with `status`, `claims`, numbered `sources`, `calculations`, `conflicts`, `warnings`, and a trace ("how I found this").

Statuses: `answered`, `insufficient_evidence`, `no_llm`, `provider_error`, `invalid_model_output`, `chitchat`.

### 5.4 Small talk

Greetings, thanks, goodbyes and "what can you do" are matched strictly (whole message only) and answered with a fixed reply. No search, no model call. "hi, what is the leave policy?" is still a real question.

---

## 6. The prompt rules (the heart of "never guess")

Give the model these rules, evidence wrapped in markers, and tell it the excerpts are untrusted data, not instructions (prompt-injection defense):

1. Answer only from the evidence. No outside knowledge.
2. Every claim cites an evidence id (`E1`) and includes a short **verbatim** quote from it.
3. If the evidence doesn't answer, set `status: "insufficient_evidence"` and list what is missing.
4. Restrictions, exceptions, conditions and limits that affect the answer must be their own claims. Never present an entitlement as unconditional when a cited restriction applies.
5. If two excerpts disagree, report both under `conflicts`. Don't pick a side.
6. Never do arithmetic. Put calculations under `calculations` as an expression over named inputs; each input must be a number in the cited evidence (or `USER` for an assumption the user typed).
7. Mark each claim `quote`, `paraphrase` or `inference`.
8. Every number in a claim must appear in the cited evidence. Computed numbers go only in `calculations`.
9. Charts: never state a chart value unless it is printed in the evidence.
10. When asked for figures, give each with its label, unit and period.

Response shape:

```json
{
  "status": "answered | insufficient_evidence",
  "claims": [{"text": "...", "type": "quote|paraphrase|inference",
              "citations": [{"evidence_id": "E1", "quote": "exact words"}]}],
  "conflicts": [{"description": "...", "evidence_ids": ["E1", "E3"]}],
  "calculations": [{"label": "...", "expression": "a * (1 + p / 100)", "unit": "...",
                    "inputs": {"a": {"value": "874.5", "evidence_id": "E1"},
                               "p": {"value": "20", "evidence_id": "USER"}}}],
  "missing_information": ["..."]
}
```

---

## 7. The validator (what makes it trustworthy)

For each claim, reject it unless:
- every `evidence_id` exists in the packet
- every quote appears **verbatim** in the cited passage (normalize whitespace and quotes)
- every number in the claim text appears in the cited passage
  - claim side: strict parsing
  - evidence side: lenient parsing; a hyphen in `2027-28` or `Feb-26` is **not** a minus sign (this caused false rejections in practice)
- paraphrase and inference claims still need a citation

Also:
- If the model said `insufficient_evidence`, or nothing survives, return `insufficient_evidence` with a plain message. Don't show unverified text.
- Warn when a retrieved restriction (exception, limit) is not mentioned in the answer.
- Flag possible conflicts between documents (same terms, different numbers) as leads, not facts.

### Calculator

A safe arithmetic evaluator using Python `ast` and `Decimal`: only `+ - * / **` (small integer exponents), parentheses, named inputs, numeric constants. Length and node-count limits. Every input must either:
- literally appear (commas ignored) in the cited evidence, or
- be `USER` and appear in the user's current or earlier question.

Results are labelled "Calculated by the system (not stated in the document)".

---

## 8. Images, charts and photos

- Parsing gives you captions, titles, axis labels and printed numbers. That text is what search sees by default.
- A **vision model** (Gemini) can read a page or a cropped figure on request. Ask for a strict JSON: `readable`, `kind`, `title`, `description`, legend, series with `approximate` flags. Rules: don't guess, mark values read from bar heights as approximate, set `readable:false` if unclear.
- Store the reading in a `visual_interpretations` table and label it everywhere: `[MODEL-READ ...; unverified]`.
- Two ways to use it:
  - **On demand:** a "Read images" toggle in chat. For the retrieved passages that are figures, read up to 2 figures per question. Results are cached.
  - **At upload:** `DESCRIBE_FIGURES=true`. Describe up to N real figures, pace the calls (about 4 s apart for free-tier rate limits), stop at the first quota error, and put the description into the figure chunk so search finds images by content.
- Hosted vision sends images to Google: require explicit consent (toggle or env flag), never default on.

---

## 9. Data model (SQLite)

Minimum tables: `documents` (hash, status, page count, index status), `ingestion_runs` (warnings, coverage), `pages`, `blocks`, `tables`, `figures`, `chunks` (+ `chunks_fts` FTS5), `facts`, `relations`, `visual_interpretations`, `conversations`, `messages` (JSON content), `feedback`. Add a simple numbered-migration list in `db.py` so the schema can evolve.

Chroma stores only `chunk_id`, embedding, and `{document_id, page_number}` metadata. SQLite is the source of truth.

---

## 10. API (FastAPI)

| Method and path | Purpose |
|---|---|
| `POST /documents` | upload PDF (background ingest + index) |
| `GET /documents`, `GET /documents/{id}/report` | status, coverage, warnings, figures |
| `POST /documents/{id}/reindex[?reparse=true]` | re-chunk/index, optionally re-parse |
| `DELETE /documents/{id}` | remove file, chunks, vectors |
| `GET /documents/{id}/pages/{n}/image?blocks=...` | page PNG with highlighted blocks |
| `GET /documents/{id}/figures/{fid}/image` | cropped figure |
| `POST /documents/{id}/pages/{n}/interpret` | read an image with the vision model |
| `POST /search` | hybrid search with scores and linked evidence |
| `POST /answer` | one-shot validated answer |
| `POST /conversations`, `GET /conversations[/{id}]`, `PATCH`, `DELETE` | chat history |
| `POST /conversations/{id}/stream` | SSE: `message`, `status`, `evidence`, then `answer` (validated, last) |
| `POST /messages/{id}/feedback` | thumbs up/down with a reason |
| `GET /health` | config summary + provider status |

The frontend proxies `/api` to the backend (Vite proxy that strips `/api`).

---

## 11. Frontend (what the UI does)

- **Layout:** left sidebar (New chat, recent chats, Documents, Search, provider pill), main chat, right source slide-over.
- **Chat:** answers as readable text with numbered sources `[1] [2]`; clicking a number opens the PDF page with the passage highlighted. Streaming progress: "Searching your PDFs…", "Found N passages…", "Reading images…", "Writing and checking the answer…".
- **Refusals:** plain "I couldn't find the answer to this in your PDFs." with the closest passages folded away; never show unverified text.
- **Tags:** inference, "read from image (unverified)", calculation card showing inputs and where each came from.
- **Composer:** Enter sends, Shift+Enter newline, document scope picker, **Read images** toggle (remembered), a hosted-mode privacy line.
- **Documents:** drag-and-drop upload, status badges, report (coverage, tables, figures, warnings), reindex/delete.
- **Search:** raw hybrid search with scores, for debugging retrieval.
- **Trace panel:** "How I found this": intent, query used, follow-up used, linked evidence and why, ignored links.
- Dark mode via `prefers-color-scheme`; mobile sidebar toggle; optional access-token prompt.

---

## 12. Safety and privacy

- A key alone never activates a hosted provider. Gemini needs `LLM_PROVIDER=gemini` **and** `ALLOW_HOSTED_LLM=true` **and** `GEMINI_ENABLED=true`.
- Exactly **one** request per call to Gemini. No retry, no fallback to another provider, no paid endpoint. On quota or errors, show Google's message and offer local mode.
- Send the key in the `x-goog-api-key` header, never in a URL; never log it; redact secrets and document text in logs.
- Show a clear "passages are sent to Google" notice in hosted mode. Free-tier content may be used by Google to improve products: use only non-confidential documents.
- Treat document text as untrusted (prompt injection): wrap it in markers, tell the model it is data.
- Optional `APP_ACCESS_TOKEN`, upload size and page limits, security headers via a pure-ASGI middleware.
- Backups: a small command that copies the DB, raw parses and the vector store.

---

## 13. Testing and evaluation

- **Unit and API tests** (hundreds are quick): chunker, normalizer, hybrid ranking, validator, calculator, small talk, follow-ups, adapters with fake transports, chat persistence, security. Slow tests (real Docling) behind a marker.
- **No network in tests.** Use fake providers and a hashing embedder so tests need no keys or model downloads.
- **Evaluation runner** over question sets (`jsonl`) with gold passages: retrieval recall and precision, citation precision/completeness, unsupported-claim rate, refusal correctness, latency. Keep thresholds in a JSON file.
- Rules that kept results honest:
  - Don't tune cases or thresholds after seeing results.
  - Don't use real/confidential documents with hosted models. Use synthetic or public ones.
  - Report failures plainly.
- Always include these canaries:
  - A synthetic policy: "birthday leave 0.5 day" on page 1, "not allowed on weekends" on page 8. The answer must include both and cite both pages.
  - A question the documents cannot answer. It must refuse.
  - A table value lookup with page citation.
  - A chart question: answer only from printed numbers or state the limitation.

---

## 14. Build order (phases), with a prompt for each

Build in this order. Commit after each phase passes its tests.

| # | Phase | Done when |
|---|---|---|
| 0 | Repo, settings, DB schema, health endpoint, free-only config | app starts, tests green |
| 1 | Upload + Docling parse + normalize + ingestion report | upload a PDF, see pages/blocks/tables/figures and warnings |
| 2 | Structure-aware chunking + SQLite FTS5 + Chroma + local embeddings | keyword and vector search return page-cited chunks |
| 3 | Hybrid search (RRF), rerank, dedupe, row cap | evaluation retrieval metrics pass |
| 4 | Knowledge layer: facts, edges, linked-evidence expansion | exception on page 8 is retrieved for an entitlement on page 1 |
| 5 | Answer pipeline: prompt, strict JSON, validator, calculator, Gemini adapter | unsupported claims rejected; refusal works; no-key mode still works |
| 6 | Chat: conversations, SSE streaming, follow-ups, feedback, trace | multi-turn works, history persists |
| 7 | Images and charts: page/figure previews, vision reading, optional describe-at-upload | figure question answered or limitation stated |
| 8 | Frontend redesign, docs, hardening, backup, evaluation reports | acceptance checklist below |

Example prompt to an AI assistant for one phase:

> Read `BUILD_GUIDE.md` (and the PRD, if you have it). Implement Phase 3 only (hybrid search). Write the tests first. Don't call any network service. Keep ruff, mypy strict and pytest green. Stop when the phase's "done when" is true and tell me what you did not verify.

---

## 15. Acceptance checklist

- [ ] With no API key, ingestion and search still work
- [ ] Synthetic birthday-leave test retrieves and cites both pages
- [ ] A question outside the documents gets a plain refusal
- [ ] Every claim shown has a verified quote and a page you can open
- [ ] Table question returns the right value with a page citation
- [ ] Chart question: answers from printed numbers or says it can't read the values
- [ ] Two conflicting documents: both shown, none chosen
- [ ] Calculation results come from Python, with each input's source shown
- [ ] Gemini quota error: clear message, no paid call, no silent fallback
- [ ] `ruff`, `mypy`, `pytest`, `tsc -b`, `npm run build` all pass

---

## 16. Lessons learned (save yourself the pain)

1. **Duplicate table headers.** Tables with "forecast" and "revision" sections repeat labels (`2027f`, `2027f`). Qualify repeats in row chunks or the model can't tell which is which.
2. **Hyphen is not minus.** `2027-28` or `Feb-26` must not parse as negative numbers in the validator.
3. **Follow-ups need the earlier passages**, not only the earlier question; a retrieval query built from two questions often misses the page the first answer used.
4. **Calculations need a source for assumptions.** "Assume 20% growth" isn't in the PDF; let the user's own words be a valid input, and verify the number is in what they typed.
5. **Models are retired.** Show Google's own error text and provide a "list callable models" command.
6. **Small talk shouldn't hit search or the model.** Otherwise "hi" produces "The evidence does not contain...".
7. **Photos have no text.** Search only sees captions; index a vision description (labelled unverified) or provide an on-demand read.
8. **Free-tier pacing.** Space vision calls, cap per document, stop on the first quota error.
9. **Reranking matters** on real documents; near-duplicate table rows will otherwise fill the top results.
10. **Keep the parse cached.** Re-indexing from stored raw JSON takes seconds; re-parsing takes minutes.
11. **Don't read or print your `.env`.** Keep keys in the environment, git-ignored.
12. **Honest limits:** only Docling-detected figures get read; a very broad question ("what is this PDF about?") may be refused; model quality caps answer quality.

---

## 17. Where to extend

- DOCX/XLSX/CSV/TXT input
- Local vision model (e.g. via Ollama) for private image description
- Embed on-demand image readings into the index
- OCR for scanned pages (set `OCR_ENABLED=true`, needs a local OCR engine)
- Export conversations and answers with citations
- Authentication for multi-user use

---

*Not legal or HR advice. Answers are retrieved from your documents and checked mechanically, but you remain responsible for verifying anything important against the source page.*

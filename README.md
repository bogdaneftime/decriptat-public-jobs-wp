# decriptat-public-jobs-wp

WordPress plugin + crawler pipeline for collecting Romanian public sector jobs and publishing them as `public_job` drafts.

## Architecture

- WordPress plugin: `wp-plugin/decriptat-public-jobs`
- Crawlers: `crawlers/`
- Sources:
  - BNR (`crawlers/sources/bnr.py`)
  - ADR (`crawlers/sources/adr.py`)

## Publishing mode

`WP_STATUS` is enforced as `draft` in crawler settings. Publishing to `publish` is blocked by validation.

## Classification

All crawlers use shared category classification:

- IT
- Juridic
- Administrativ
- Economic / Financiar
- Comunicare / PR
- Resurse Umane
- Audit / Control / Conformitate
- Tehnic / Inginerie
- Achizitii / Proiecte
- Altele

Flow:

1. Deterministic keyword rules.
2. OpenAI fallback only for ambiguous cases.
3. Safe fallback to rule result if key is missing / API fails / response is invalid.
4. `is_it = true` only when final category is `IT`.

## ADR crawler behavior

ADR source URL: `https://www.adr.gov.ro/cariera`

The ADR crawler:

- crawls listing + pagination pages
- extracts details URL, title, publication date
- keeps only recruitment announcements (concurs/examen/recrutare/ocupare post and transfer only in recruitment context)
- skips obvious non-job notices (for example generic erata/corrigendum)
- keeps only items inferred as year `2026`
- extracts attachments and keeps links
- attempts lightweight attachment text extraction:
  - PDF via `pypdf`
  - DOCX via `python-docx`
- combines details page text + attachment labels + extracted attachment text for:
  - category classification
  - deadline extraction
- detects deadline from Romanian deadline phrases and date patterns
- sets `expired` from parsed deadline
- publishes as `public_job` draft with:
  - title `[ADR] <title>`
  - institution taxonomy: `Autoritatea pentru Digitalizarea Romaniei`
  - `job_category` taxonomy
  - meta: `source_url`, `published_date`, `deadline`, `location`, `is_it`, `expired`

## WordPress frontend active vs expired behavior

- Default archive and shortcodes show active jobs only.
- Expired jobs can still be consulted:
  - direct URL to single post
  - `?status=expired` filter
  - `?status=all` filter
- Sorting:
  - active first
  - expired later when included
  - active jobs ordered by nearest deadline first

## Environment variables

See `.env.example`.

- `WP_BASE_URL` (required)
- `WP_USER` (required)
- `WP_APP_PASS` (required)
- `WP_STATUS` (must stay `draft`)
- `STORAGE_PATH` (sqlite file)
- `OPENAI_API_KEY` (optional)
- `OPENAI_MODEL` (optional, default `gpt-4o-mini`)

## Install dependencies

```bash
pip install -r requirements.txt
```

## Run crawlers

BNR:

```bash
python crawlers/run_bnr.py
```

ADR:

```bash
python crawlers/run_adr.py
```

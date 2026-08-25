# MangroScan Rich-Text Persistence Contract

This is the canonical frontend/backend contract for human-authored report prose. It applies to the `summary`, `interpretation`, `limitations`, and `recommendations` fields accepted by RPT-02 and RPT-04 and returned by the report draft/detail resources.

## Wire and storage format

- Send and receive a JSON string containing UTF-8 Markdown (`text/markdown; charset=utf-8`).
- The database stores the Markdown source in the existing PostgreSQL `TEXT` columns. Do not send HTML, editor-specific JSON/delta documents, base64 content, or a second rendered field.
- The backend trims whitespace only at the outer boundary. Interior line breaks, paragraph breaks, list indentation, and Markdown markers are preserved. A blank optional field is normalized to JSON `null`.
- Each rich-text field is limited to 20,000 characters by the current Form Requests.
- API responses return the stored Markdown source, never server-rendered HTML. Clients should keep that source as the editable value.

No migration or format discriminator is required: the four named fields are Markdown by contract. `report_title` and `audience` remain plain text. Operational `notes`, `description`, and `mission_objective` fields also remain plain text unless a future endpoint contract explicitly opts them into this format.

## Supported authoring subset

Frontend editors may produce:

- paragraphs and line breaks;
- headings;
- bold and italic emphasis;
- ordered and unordered lists;
- block quotes;
- inline code and fenced code blocks;
- links using `http`, `https`, or `mailto` destinations.

Raw HTML, scripts, styles, iframes, embedded media/images, data URLs, JavaScript URLs, tables, task-list controls, and editor-specific extensions are outside the supported contract. A frontend renderer must disable raw HTML and sanitize the rendered result before inserting it into the DOM. The Markdown source itself should be treated as untrusted user content.

## Example

Request fragment:

```json
{
  "summary": "## Findings\n\n- **12** validated trees\n- [Open field protocol](https://example.test/protocol)",
  "interpretation": "Canopy recovery is *stable* across the sampled plots.",
  "limitations": null,
  "recommendations": "1. Repeat the eastern transect.\n2. Review low-confidence observations."
}
```

RPT-02 and RPT-04 return the same Markdown strings after outer trimming. When a field is omitted during RPT-04, its existing value is preserved; sending JSON `null` clears it.

## Frontend implementation rules

1. Configure the rich-text editor to serialize Markdown source, not HTML or an editor document model.
2. Submit the Markdown string directly in the JSON field.
3. Use the returned API value as the authoritative saved value after create/update.
4. Render with raw HTML disabled and URL schemes restricted to `http`, `https`, and `mailto`.
5. Apply the 20,000-character limit to the Markdown source, including formatting markers.

Generated reports currently consume these fields as text content. The source Markdown remains intact in the API/database so richer presentation can evolve without a data migration.

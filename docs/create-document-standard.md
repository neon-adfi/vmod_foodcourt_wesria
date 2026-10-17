# Vmod operational document standard

The generic ERP MVP contract is authoritative. Documents are operational records, not issued tax invoices or accounting entries.

## Authorization and transport

Use native October components, Twig and existing Tabler assets. Every PO/SO/GRN/DO preview requires the frontend identity, its document-specific read permission and current company/authorized branch scope. A requested PO/SO revision must belong to the requested parent. Never accept an actor or owner from the browser. Unknown/foreign records return 404; forbidden reads return 403; signed-out navigation goes to `/auth` without document content.

Serve private, no-store HTML at `/documents/:kind/:id/print`; `kind` is an explicit `po`, `so`, `grn` or `do` allowlist. PO/SO accept an optional `revision` query value; omission selects the current saved revision. GRN/DO always use their own exact originating revision. Never publish PDF files or bypass access checks through AJAX/partials. Browser Print / Save as PDF uses the authenticated view, with no server PDF library.

## Printed content

PO/SO: issuer company/branch, saved counterparty/contact/address, document number/date, revision, saved item/package/UOM, quantities, unit price, tax labels/rates, price mode, currency and stored line/document net/tax/gross. Never recalculate from current catalogue values.

GRN/DO: issuer/counterparty, number/date, source order/revision, item/package/UOM, quantities and stock location. GRN includes supplier delivery reference. DO includes saved notes. Omit commercial prices and taxes. Do not mix corrections into original quantities.

Every saved record is printable. Show prominent DRAFT, VOIDED and CLOSED SHORT labels as applicable. For older order revisions show HISTORICAL REVISION and the revision's approval status separately from the parent's current state. Show correction/closure reasons and predecessor/replacement references. Printing is read-only and does not approve, post, number or issue anything.

## Historical names

New PO/SO revisions retain issuer snapshots; existing snapshots copy forward. Capture a missing issuer snapshot only in a newly created revision. New GRN rows capture receiving-location names; DOs inherit the fixed SO line location snapshot. No historical backfill. A missing historical snapshot shows current values alongside: **Historical snapshot unavailable; current value shown**.

## Presentation and acceptance

Escape every user value; preserve text line breaks with CSS rather than raw HTML. Use a readable A4 print layout, repeated table headings, clear totals and wrapping long text. Hide navigation and print controls on paper; show no unsaved form values. Native print controls work by keyboard. Verify desktop/mobile previews, print/PDF output, long documents, statuses, exact revision access, missing snapshots and direct unauthorized requests in egolite. Do not claim successful OS printer delivery.

## Repair acceptance: print sizing

Commercial lines use a full-width item/package row followed by five aligned numeric columns. Keep each item with its quantities and amounts across page breaks. Numbers remain intact; descriptions wrap. Verify at the actual 186mm A4 content width (12mm page margins), including supported decimal boundaries and long names. Numeric cells retain readable gutters; screen-width measurements alone do not qualify print output. Inspect every generated PDF page.

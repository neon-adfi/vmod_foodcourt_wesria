# October CMS Frontend Form Handling Standards

> **Audience:** AI agents and engineers changing October CMS frontend forms.
> **Scope:** Theme/plugin CMS pages, partials, offcanvas forms, AJAX submit behavior, Select2/Flatpickr setup, validation display, and SweetAlert response handling.
> **Wesria authority:** The ERP MVP plan takes precedence. Use October 4.3/Laravel 12, native validation, Vmod identity and Tabler 1.5.1. Earlier Gaia screenshots/paths below are historical behavior references only. Existing helper names are examples, not dependencies to copy; use native October/Tabler equivalents unless a real reusable gap exists.

---

## 1. Default Pattern

Use the Sales Order create/update flow as the frontend handling standard:

- Form submit uses an October AJAX `data-request` handler.
- Backend submit validation is centralized at the request boundary, preferably with a `FormRequest`.
- Domain and workflow rules stay in services or validators.
- On success, refresh the affected table/view, close the offcanvas, show `swalToast()`, and reinitialize any required UI helpers.
- On validation error, keep the form and offcanvas open, preserve entered values, and render field errors with `showFieldsError(data)`.

Reference files:

- `themes/gaiafoodtech/partials/salesorder/salesorder_form.htm`
- `plugins/gaiafoodtech/order/http/requests/SaveSaleOrderRequest.php`
- `themes/gaiafoodtech/partials/site/js.htm`

---

## 2. Validation Layers

Frontend attributes are user-experience hints only:

- `required`
- `min`
- `max`
- `step`
- `type="number"`
- Select2 option lists
- Flatpickr date formatting

They must not be the source of truth.

Server-side validation must exist for every create/update submit path:

- Use native `Validator` and throw `October\Rain\Exception\ValidationException` on field errors; do not import Vector utilities.
- Validate payload/row shape before normalization; validate normalized field values before domain operations.
- Use explicit allowlisted rules for submit contract validation.
- Use service validators for domain invariants, workflow state, permissions, and downstream consistency.
- Do not duplicate complex domain rules in Twig or JavaScript.

Recommended handler shape:

```php
public function onSaveDocument()
{
    $validator = Validator::make(post(), ['name' => 'required|string|max:255']);
    if ($validator->fails()) {
        throw new \October\Rain\Exception\ValidationException($validator);
    }
    $document = (new DocumentService())->save($validator->validated());

    $this->page['document'] = $document->fresh();
}
```

---

## 3. Frontend User Identity Fields

Frontend forms use workspace users only.

Rules:

- Any `*_user_id` accepted from a frontend form, URL, AJAX payload, or component state must validate against frontend workspace users (`users.id`), not `backend_users`.
- Use `Vmod\Core\Models\User`, `Auth::getUser()`, and `Workspace` for frontend identity and permission context.
- Frontend user selectors must query only users allowed in the current workspace and handlers must re-check the exact server-side permission.
- Do not expose `backend_users`, `Backend\Models\User`, or `BackendAuth` in CMS pages, JavaScript payloads, hidden inputs, request validation, or frontend component state.
- Never write a mixed auth fallback like `BackendAuth::getUser() ?: Auth::getUser()` into one user column.
- If a frontend or print service method has a nullable backend-user audit parameter, frontend callers pass `null`; add a frontend-user or polymorphic actor field only through a separate approved schema plan.

---

## 4. AJAX Form Markup

Forms that expect field-level errors should use:

```html
<form
    data-request="component::onSave"
    data-request-validate
    data-request-error="showFieldsError(data); return false;"
    data-request-success="
        oc.Events.dispatch('datatable.refresh');
        bootstrap.Offcanvas.getOrCreateInstance('#offcanvasForm').hide();
        swalToast('Saved Successfully');
    "
>
</form>
```

Rules:

- Use `data-request-validate` when backend validation can return field errors.
- Use `data-request-error="showFieldsError(data); return false;"` for normal form validation errors.
- Add `data-request-flash` when the handler relies on October flash messages.
- Keep `data-request-success` short: refresh, close, toast, reinit.
- Do not hide the offcanvas in `data-request-error`.
- Do not show a success toast until the request succeeds.

For workflow buttons that open another offcanvas before the AJAX response, the error handler must restore the previous state instead of leaving an empty offcanvas.

---

## 5. Field Error Markup

Every server-validated field should have a visible error target.

Simple field:

```html
<input class="form-control" name="title" required>
<div class="invalid-feedback d-block" data-validate-for="title"></div>
```

Nested array field:

```html
<input class="form-control" name="items[0][quantity_ordered]" required>
<div class="invalid-feedback d-block" data-validate-for="items.0.quantity_ordered"></div>
```

Rules:

- Field names must match backend validation keys.
- Nested input names use bracket syntax in HTML and dot syntax in `data-validate-for`.
- Use `showFieldsError(data)` for consistent `.is-invalid` state and message placement.
- Keep labels visible; placeholders are not labels.
- Required fields need both backend `required` rules and frontend required indicators.

---

## 6. Success And Error Responses

Success response rules:

- Refresh the affected DataTable with `oc.Events.dispatch('datatable.refresh')` or the specific table reload already used by the page.
- Close the offcanvas only after success.
- Show `swalToast('... Successfully')`.
- Reinitialize UI helpers when the updated DOM needs them.
- Return partial updates or small response data from the handler when the frontend needs to navigate or open a detail panel.

Error response rules:

- Keep the current form open.
- Preserve user-entered values.
- Use `showFieldsError(data)` for validation errors.
- Use Swal error dialogs for non-field application errors.
- Do not replace the form body with an empty partial on failure.

Global helpers live in `themes/gaiafoodtech/partials/site/js.htm`:

- `showFieldsError(data)`
- `swalToast(msg, type = 'success')`

---

## 7. SweetAlert And Toast Standard

SweetAlert2 is loaded globally through `themes/gaiafoodtech/partials/site/js.htm`.
Use the shared helper and existing quote/request patterns instead of creating new popup styles.

Reference patterns:

- Print Request save uses a success toast only after the AJAX save succeeds.
- Quote approval uses a blocking `Swal.fire()` confirmation before the approval AJAX request.
- Quote rejection and quote void requests use a blocking `Swal.fire()` textarea with a required trimmed reason.
- Quote/request offcanvas transitions restore the previous offcanvas on AJAX error and do not show a success toast.

Toast rules:

- Use `swalToast(msg, type = 'success')` for non-blocking result notices after the server confirms the action.
- Use it for create, update, delete, upload, and status-transition success messages.
- Call it from `data-request-success` or an October AJAX `success` callback after `this.success(data)`.
- Do not show a success toast before the server-side action succeeds.
- Do not use a toast for decisions, required reasons, field validation errors, pre-submit warnings, or errors that must stop the workflow.
- Use `type` values such as `success`, `warning`, `info`, or `error` only when the message can be safely dismissed without blocking the workflow.

Blocking dialog rules:

- Use `Swal.fire()` for confirmations before workflow or state changes.
- Use `Swal.fire()` for warnings where cancel must leave data unchanged.
- Use `Swal.fire()` for required notes or reasons, with `input: 'textarea'` and an `inputValidator` that rejects empty or whitespace-only input.
- Use `Swal.fire()` for non-field application errors when the user must stop and read the message.
- If the popup is triggered from an offcanvas workflow, target the nearest active offcanvas and set `returnFocus: false` to avoid focus jumping after the popup closes.

Offcanvas dialog base options:

```js
function offcanvasSwalOptions(button, options) {
    const offcanvas = button && button.closest
        ? button.closest('.offcanvas.show') || button.closest('.offcanvas')
        : null;

    return Object.assign({
        target: offcanvas || document.body,
        returnFocus: false
    }, options);
}
```

Canonical confirmation options:

```js
Swal.fire(offcanvasSwalOptions(button, {
    icon: 'warning',
    title: 'Cancel approval request?',
    text: 'This quote will return to draft so it can be updated and submitted again.',
    showCancelButton: true,
    confirmButtonText: 'Cancel Approval Request',
    cancelButtonText: 'Keep Approval Request',
    reverseButtons: true,
    buttonsStyling: false,
    customClass: {
        confirmButton: 'btn btn-danger',
        cancelButton: 'btn btn-label-secondary'
    }
})).then(function(result) {
    if (!result.isConfirmed) {
        return;
    }

    // Run the mutating AJAX request here.
});
```

Canonical required reason options:

```js
Swal.fire(offcanvasSwalOptions(button, {
    icon: 'warning',
    title: 'Reject quote?',
    input: 'textarea',
    inputLabel: 'Reason',
    inputPlaceholder: 'Enter rejection reason',
    inputAttributes: {
        'aria-label': 'Rejection reason'
    },
    showCancelButton: true,
    confirmButtonText: 'Reject Quote',
    cancelButtonText: 'Cancel',
    reverseButtons: true,
    buttonsStyling: false,
    customClass: {
        confirmButton: 'btn btn-danger',
        cancelButton: 'btn btn-label-secondary'
    },
    inputValidator: function(value) {
        if (!String(value || '').trim()) {
            return 'Reason is required.';
        }

        return undefined;
    }
})).then(function(result) {
    if (!result.isConfirmed) {
        return;
    }

    const reason = String(result.value || '').trim();
    // Send `reason` in the AJAX payload here.
});
```

Button class rules:

- Use `btn btn-primary` for normal confirm actions.
- Use `btn btn-success` for approval or positive workflow actions.
- Use `btn btn-danger` for destructive, rejection, void, or cancellation actions.
- Use `btn btn-label-secondary` for cancel buttons.
- Set `buttonsStyling: false` whenever Bootstrap button classes are supplied through `customClass`.

Avoid browser-native confirmations:

- Do not introduce `window.confirm()` for new frontend/offcanvas workflows.
- `data-request-confirm` is acceptable only for simple, low-risk, one-line confirmations.
- Quote, print request, approval, rejection, void, warning, and required-reason flows must use explicit `Swal.fire()` handling.

---

## 8. Offcanvas Form Standard

Use offcanvas forms for dense create/update workflows that match Sales Order behavior.

Structure:

- `.offcanvas-header` with title and close button.
- `.offcanvas-body mx-0 flex-grow-0` plus the project stretch class when Select2 dropdown space is needed.
- One root form per submit action.
- `card` or section grouping only where it improves scanning.
- Footer actions aligned right: Cancel, then primary submit.

Rules:

- Cancel closes the offcanvas without submitting.
- Submit button must be `type="submit"`.
- Do not close the offcanvas before AJAX success.
- If a form is loaded dynamically, initialize widgets inside that form/offcanvas scope.
- Use class-based layout fixes, not inline styles.

For Select2 dropdown clipping, use the shared offcanvas body stretch class instead of inline CSS.

### Request Detail Offcanvas Pattern

Print request and quote workflow detail offcanvas screens should follow the Sales Order detail/action layout:

- Header shows the request number/title and compact status badges inline with the heading; keep moved badges visually small with a class such as `fs-6`.
- Detail summary cards own read-only request context and linked documents; they should not own workflow actions or edit buttons.
- Item table cards use `card mb-3 bg-lighter`, with the table wrapped by `card-body p-0`.
- Item tables use `table table-sm table-hover align-middle`.
- Request edit/workflow actions live in a bottom `card-footer`, right aligned with `justify-content-end align-items-center flex-wrap gap-2`.
- Use label button classes consistently for offcanvas action rows, such as `btn-label-primary` for normal actions and `btn-label-danger` for destructive actions.

Workflow action text and order:

- Use `Void`, not `Cancel`, for request void/cancellation actions.
- Render `Void` as the first action, followed by a vertical separator, then workflow and document actions.
- Keep backend payload/status names unchanged, for example `commercial_status: 'cancelled'`, unless the domain contract is explicitly renamed.
- Status transitions and resume/void-related actions should refresh both the request header and the item/action area.
- Workflow buttons that open quote/job offcanvas before the AJAX response must restore the previous request detail offcanvas on AJAX error.

Exception: Artwork, Print Job, and Sales Order Create/Open actions represented as canonical print-pipeline stages live in the shared mini/full pipeline, not in request or quote card footers. Do not duplicate them in footers. Pipeline mutations still require this document's confirmation, validation, blocking-error, success-toast, permission, and accessibility rules.

---

## 9. Select2 And Date Picker Setup

Initialize UI widgets after the AJAX partial is inserted, scoped to the dynamic form or offcanvas shell.

Select2 pattern:

```html
<form
    x-data
    x-init="$nextTick(() => { $select2('.select2', $el); })"
>
</form>
```

Flatpickr date-time pattern, used by Print Request `received_at` and `requested_due_at`:

```html
<div
    x-data="{
        initDateTimePickers() {
            if (!window.flatpickr) return;
            flatpickr(this.$refs.received_at, {
                enableTime: true,
                dateFormat: 'Y-m-d H:i',
                allowInput: true,
                defaultDate: this.$refs.received_at.value || null
            });
        }
    }"
    x-init="$nextTick(() => { initDateTimePickers(); })"
>
    <input x-ref="received_at" class="form-control" type="text" name="received_at" placeholder="YYYY-MM-DD HH:mm">
</div>
```

Flatpickr date-only pattern, used by Payment Collection `expected_date` and `payment_date`:

```html
<div
    x-data="{
        initDatePickers() {
            if (!window.flatpickr) return;
            this.$el.querySelectorAll('.date-picker').forEach((input) => {
                if (input._flatpickr) return;
                flatpickr(input, {
                    dateFormat: 'Y-m-d',
                    allowInput: true,
                    defaultDate: input.value || null
                });
            });
        }
    }"
    x-init="$nextTick(() => { initDatePickers(); })"
>
    <input class="form-control date-picker" type="text" name="expected_date" placeholder="YYYY-MM-DD">
</div>
```

Rules:

- Initialize Select2 and Flatpickr after the partial is inserted into the DOM.
- Scope initialization with `$el` to avoid touching unrelated forms.
- Date-time fields use `dateFormat: 'Y-m-d H:i'` and `enableTime: true`.
- Date-only fields use `dateFormat: 'Y-m-d'`; do not add time storage unless the backend contract changes.
- Flatpickr-owned inputs should use `type="text"`, not native `type="date"`.
- Skip fields that already have `_flatpickr`.
- Include Flatpickr CSS/JS on every page that can open a dynamic partial containing Flatpickr fields.
- For offcanvas/modal forms, the Select2 dropdown parent must be the nearest `.offcanvas` or `.modal` context.
- Do not initialize an already-initialized `.select2-hidden-accessible` element again.
- For rows added by Alpine repeaters, initialize only new Select2 fields after `$nextTick`.

---

## 10. Future Form Checklist

Before marking a frontend form complete:

- Backend create/update submit has request-boundary validation.
- Domain/workflow rules live in service validators, not Twig/JavaScript.
- Form uses `data-request-validate` where field errors are expected.
- Form uses `showFieldsError(data)` and keeps offcanvas open on error.
- Success handler refreshes the affected surface, closes the offcanvas, and shows `swalToast()`.
- Success toast fires only after successful AJAX completion.
- Confirmation, warning, and required-reason flows cancel cleanly, do not mutate on dismiss, and show no success toast on error.
- Select2 and date picker setup is scoped to the dynamic form/offcanvas.
- Select2 dropdowns are not clipped inside offcanvas.
- Nested fields have matching `data-validate-for` error targets.
- Required labels, frontend hints, and backend rules agree.
- Browser check covers success, validation error, and offcanvas close behavior.

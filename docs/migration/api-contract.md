# VANTIQ API Contract Inventory

Base: `/api`

The legacy API uses JSON success envelopes `{success:true,data:<payload>,error:null}` and JSON errors `{success:false,data:null,error:{code,message,details,reference_id?}}`. Mutating endpoints require `X-CSRF-Token` and the PHP session cookie `VANTIQSESSID`; cookies are `HttpOnly`, `SameSite=Lax`, secure in production. Public auth endpoints are exceptions to authentication.

## Contract

| Method | Path | Auth | Permission / tenant | Request | Success data |
|---|---|---|---|---|---|
| GET | `/health` | Public | — | — | `{status,php_version,database:{connected}}` |
| GET | `/auth/csrf` | Session | — | — | `{csrfToken}` |
| POST | `/auth/register` | Public | — | `{organisation_name,name,email,password}` | `{user}` |
| POST | `/auth/login` | Public | — | `{email,password}` | `{user,csrfToken}` |
| POST | `/auth/logout` | Optional | — | — | `{loggedOut}` |
| GET | `/auth/me` | Required | active session | — | `{user}` |
| POST | `/auth/change-password` | Required | active session | `{current_password,new_password}` | `{changed,csrfToken}` |
| POST | `/auth/forgot-password` | Public | — | `{email}` | `{message}` |
| POST | `/auth/reset-password` | Public | — | `{token,password}` | `{reset,csrfToken}` |
| PATCH | `/auth/accounts/{id}/status` | Required | `users.manage`; tenant owner rules | `{status}` | `{account:{id,status}}` |
| PUT | `/auth/accounts/{id}/roles` | Required | `users.manage`; cannot cross tenant/grant beyond actor | `{role_ids:number[]}` | `{assignment:{user_id,roles}}` |
| GET | `/finance/summary` | Required | `finance.read`; organisation scope | — | module summary object |
| GET | `/finance/sales` | Required | `finance.read`; organisation scope | query `{limit?,offset?}` | `{sales,limit,offset}` |
| POST | `/finance/sales` | Required | `finance.write`; organisation scope | `{sale_date?,branch_id?,department_id?,customer_id?,discount?,tax?,items[]}` | created sale object |
| POST | `/finance/sales/{id}/refund` | Required | `finance.write`; organisation scope | `{reason}` | `{id,status,amount}` |
| GET | `/finance/expenses` | Required | `finance.read`; organisation scope | query `{limit?,offset?}` | `{expenses,limit,offset}` |
| POST | `/finance/expenses` | Required | `finance.write`; organisation scope | `{expense_date?,amount,description,branch_id?,department_id?,category_id?}` | `{id,amount}` |
| GET | `/finance/invoices` | Required | `finance.read`; organisation scope | — | `{invoices}` |
| POST | `/finance/invoices` | Required | `finance.write`; organisation scope | `{customer_id,branch_id?,issue_date?,due_date?,tax_amount?,items[]}` | `{id,invoice_number,total,status}` |
| GET | `/finance/invoices/{id}` | Required | `finance.read`; organisation scope | — | invoice detail object |
| POST | `/finance/invoices/{id}/payments` | Required | `finance.write`; organisation scope | `{payment_date?,amount,method,reference?}` | invoice payment result |
| GET | `/finance/invoices/{id}/credit-notes` | Required | `finance.read`; organisation scope | — | credit note list |
| POST | `/finance/invoices/{id}/credit-notes` | Required | `finance.write`; organisation scope | `{reason,items[],refund_method?,refund_reference?}` | credit note result |
| GET | `/finance/customers` | Required | `finance.read`; organisation scope | — | `{customers}` |
| POST | `/finance/customers` | Required | `finance.write`; organisation scope | `{name,email?}` | `{id,name,email,status}` |
| GET | `/operations/summary` | Required | `operations.read`; organisation scope | — | module summary object |
| GET | `/operations/products` | Required | `operations.read` or `finance.read`; organisation scope | — | `{products}` |
| POST | `/operations/products` | Required | `operations.write`; organisation scope | `{name,sku,unit_cost,selling_price,reorder_level,branch_id?,category_id?}` | `{id,stock_level}` |
| POST | `/operations/products/{id}/movements` | Required | `operations.write`; organisation scope | `{movement_type,quantity,branch_id?,unit_cost?,notes?}` | `{id,product_id,stock_level}` |
| GET | `/operations/suppliers` | Required | `operations.read`; organisation scope | — | `{suppliers}` |
| POST | `/operations/suppliers` | Required | `operations.write`; organisation scope | `{name,contact_email?}` | `{id,name,contact_email,status}` |
| GET | `/operations/purchase-orders` | Required | `operations.read`; organisation scope | — | `{purchase_orders}` |
| POST | `/operations/purchase-orders` | Required | `operations.write`; organisation scope | `{supplier_id,branch_id?,order_date?,expected_date?,items[]}` | `{id,total,status}` |
| POST | `/operations/purchase-orders/{id}/submit` | Required | `operations.write`; organisation scope | — | `{id,status}` |
| POST | `/operations/purchase-orders/{id}/receive` | Required | `operations.write`; organisation scope | — | `{id,status,item_count}` |
| GET | `/reports/summary` | Required | `reports.read`; organisation scope | — | module summary object |
| GET | `/reports/financial` | Required | `reports.read`; organisation scope | query `{from?,to?}` | financial report object |
| GET | `/forecast/summary` | Required | `operations.read`; organisation scope | — | module summary object |
| GET | `/risk/alerts` | Required | `risks.read`; organisation scope | — | `{alerts}` |
| PATCH | `/risk/alerts/{id}/status` | Required | `risks.manage`; organisation scope | `{status,reviewer_user_id?,resolution_notes?}` | updated alert object |
| GET | `/decisions/scenarios` | Required | `decisions.manage`; organisation scope | — | `{scenarios}` |
| POST | `/decisions/scenarios` | Required | `decisions.manage`; organisation scope | `{name,description?,status?,assumptions?}` | created scenario object |
| GET | `/decisions/scenarios/{id}` | Required | `decisions.manage`; organisation scope | — | scenario detail object |
| GET | `/connect/imports` | Required | `connect.manage`; organisation scope | — | import history object |
| POST | `/connect/imports/preview` | Required | `connect.manage`; organisation scope | `{file_name,csv}` | preview object |
| GET | `/admin/overview` | Required | `users.manage`; organisation scope | — | module overview object |
| GET | `/admin/users` | Required | `users.manage`; organisation scope | — | module users object |
| GET | `/admin/branches` | Required | `users.manage`; organisation scope | — | module branches object |
| GET | `/admin/departments` | Required | `users.manage`; organisation scope | — | module departments object |
| GET | `/admin/roles` | Required | `users.manage`; organisation scope | — | `{roles}` |
| GET | `/admin/audit-logs` | Required | `users.manage`; organisation scope | — | `{audit_logs}` |
| GET | `/admin/outbox/failures` | Required | `users.manage`; organisation scope | — | `{events}` |
| POST | `/admin/outbox/events/{uuid}/replay` | Required | `users.manage`; organisation scope | — | `{event_id,status}` |
| POST | `/admin/branches` | Required | `users.manage`; organisation scope | `{name,code}` | `{id,name,code}` |
| POST | `/admin/departments` | Required | `users.manage`; organisation scope | `{name,code,branch_id?}` | `{id,name,code}` |
| GET | `/documents` | Required | `documents.read` or `documents.upload`; organisation scope | query `{search?,category?}` | `{documents,categories}` |
| POST | `/documents/upload` | Required | `documents.upload`; organisation scope | multipart `file`, `category` | `{document,duplicate,extraction_status,text}` |
| GET | `/documents/versions/{uuid}` | Required | `documents.read` or `documents.upload`; organisation scope | — | `{versions}` |
| GET | `/documents/{id}/extraction` | Required | `documents.read` or `documents.upload`; organisation scope | — | `{extraction}` |
| POST | `/documents/{id}/review` | Required | `documents.upload`; organisation scope | — | `{reviewed}` |
| GET | `/imports` | Required | `data.import` or `connect.manage` or `finance.write`; organisation scope | — | `{imports}` |
| POST | `/imports/upload` | Required | same import permissions; organisation scope | multipart `file`, `data_type` | `{batch_id,file_name,data_type,headers,fields,rows,row_count}` |
| GET | `/imports/templates/{type}` | Required | same import permissions; organisation scope | — | CSV blob |
| POST | `/imports/{id}/validate` | Required | same import permissions; organisation scope | `{mapping}` | `{valid,row_count,error_count,preview}` |
| POST | `/imports/{id}/commit` | Required | same import permissions; organisation scope | — | `{status,records_imported}` |
| POST | `/imports/{id}/rollback` | Required | same import permissions; organisation scope | — | `{status}` |
| GET | `/integrations/organisations` | Required | `platform.users.manage` | — | `{organisations}` |
| GET | `/integrations` | Required | tenant `integrations.manage` or platform admin | query `{organisation_id?}` | `{integrations}` |
| POST | `/integrations` | Required | tenant `integrations.manage` or platform admin | integration payload plus optional `organisation_id` | `{integration_id,status}` |
| POST | `/integrations/test` | Required | tenant `integrations.manage` or platform admin | integration payload plus optional `organisation_id` | `{connected,http_status,records_detected}` |
| POST | `/integrations/{id}/activate` | Required | tenant `integrations.manage` or platform admin | query `{organisation_id?}` | `{status,records_synced}` |
| POST | `/integrations/{id}/sync` | Required | tenant `integrations.manage` or platform admin | query `{organisation_id?}` | `{status,records_synced,message}` |
| GET | `/events/stream` | Required | module permission derived from RBAC; organisation scope | query `{after?}` / `Last-Event-ID` | SSE `business-change` and heartbeat/checkpoint events |

## Status and validation contract

- JSON payload validation failures use `422 validation_failed` with `details` keyed by field.
- Missing/expired auth uses `401 unauthenticated` or `session_expired`.
- Missing/invalid CSRF uses `419 csrf_failed`.
- Forbidden RBAC uses `403 forbidden`; tenant/organisation errors use `403 tenant_required` or `403 forbidden`.
- Not found uses `404` domain-specific codes.
- Unsupported media types use `415`; oversized JSON/uploads use `413`.

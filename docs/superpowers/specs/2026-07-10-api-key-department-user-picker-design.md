# API Key Department-First User Picker Design

## Goal

Make API Key creation select a department before a user, and constrain the user search to employees directly assigned to that department.

## Interaction

The form shows a department selector first.  The employee control is a single searchable picker rather than the current separate search field and native user selector.  Until a department is selected, the picker is disabled with a clear prompt.  Changing the department clears both the selected employee and any query.  Search matches username, role, and status only among users whose `department_id` exactly equals the selected department; child departments are excluded.

## Data and validation

The picker retains the existing `user_id` form field so the create request contract remains unchanged.  The existing `department_id` field remains required.  `ApiKeyService::create()` will load the selected user and reject creation when its direct `department_id` does not match the submitted department, so API clients cannot submit a mismatched pair.

## Testing

Frontend source-contract coverage will assert department-first ordering and the department-filtered, single picker behavior.  Service coverage will assert a matching user/department pair can create a key and a mismatched pair is rejected before insertion.

# Organizations and departments

Status: DEFERRED to I3. Identity owns internal organizational membership, but I1 ships no organization tables or endpoints and does not confuse internal organizations with commercial Business Partner records.

## Pre-drawn design (must be preserved when implementing)

- Organizations, departments with hierarchy, memberships (user↔organization many-to-many, user↔department many-to-many), membership lifecycle.
- Cross-organization access denied by default; organization administrators get an explicit authorization boundary.
- No organization/department/employee codes are generated inside Identity; the Data Service allocates codes where required.
- Access tokens never embed organization claims; the actor-context `organizationId` field (null in I1) will carry the selected context after I3.

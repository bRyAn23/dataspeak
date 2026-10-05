# database/

Schema design artifacts for Data Speak: ERD exports, the data dictionary working copies, and
notes. **Laravel migrations live in `backend/database/migrations/`**, which is the only place the
schema is executed from. Nothing here is applied automatically.

The authoritative schema source is `docs/Data_Speak_Data_Dictionary.xlsx`; its Enumerations sheet
governs every status, stage, type and role value.

UPDATE audit_log
SET context_json='{"_redacted":"sensitive historical form context removed"}'
WHERE context_json IS NOT NULL
  AND (
    context_json LIKE '%"secret_key"%'
    OR context_json LIKE '%"smsru_api_id"%'
    OR context_json LIKE '%"api_key"%'
    OR context_json LIKE '%"authorization"%'
    OR context_json LIKE '%"cookie"%'
    OR context_json LIKE '%"private_key"%'
    OR context_json LIKE '%"credential"%'
  );

-- Guidance app seed data for Supabase (integration-ready sample dataset)
SET search_path TO guidance, public;

DELETE FROM integration_flows
WHERE correlation_id IN (
  'seed-guidance-registrar-0001',
  'seed-guidance-pmed-0002',
  'seed-pmed-guidance-0003',
  'seed-prefect-guidance-0004'
);

DELETE FROM integration_routes
WHERE route_key IN (
  'guidance_to_registrar_counseling_report',
  'guidance_to_pmed_counseling_report',
  'guidance_to_registrar_student_case_summary',
  'guidance_to_prefect_behavior_referral',
  'guidance_to_prefect_discipline_report',
  'guidance_to_pmed_incident_monitoring_update',
  'guidance_to_pmed_wellness_summary',
  'registrar_to_guidance_student_profile_sync',
  'pmed_to_guidance_monitoring_summary',
  'prefect_to_guidance_offense_report'
);

DELETE FROM integration_departments
WHERE department_key IN (
  'guidance',
  'registrar',
  'prefect',
  'pmed'
);

DELETE FROM survey
WHERE student_id IN ('2026-0001', '2026-0002');

DELETE FROM crisis
WHERE case_reference IN ('CRI-2026-0001', 'CRI-2026-0002');

DELETE FROM counseling
WHERE case_reference IN ('COU-2026-0001', 'COU-2026-0002');

DELETE FROM guidance
WHERE case_reference IN ('GDN-2026-0001', 'GDN-2026-0002');

DELETE FROM students
WHERE student_id IN ('2026-0001', '2026-0002', '2026-0003');

DELETE FROM users
WHERE email IN (
  'admin@guidance.local',
  'counselor@guidance.local',
  'student.one@guidance.local',
  'teacher.one@guidance.local'
);

INSERT INTO users (name, email, password, role)
VALUES
  ('Guidance Admin', 'admin@guidance.local', 'admin123', 'admin'),
  ('Lead Counselor', 'counselor@guidance.local', 'counselor123', 'admin'),
  ('Student One', 'student.one@guidance.local', '12345', 'student'),
  ('Faculty Adviser', 'teacher.one@guidance.local', '12345', 'teacher');

INSERT INTO students (
  student_id, name, course, year_level, section_name, enrollment_status, registrar_status, external_profile, synced_at
)
VALUES
  (
    '2026-0001',
    'Alyssa Ramos',
    'BSIT',
    '3rd Year',
    'BSIT-3A',
    'Enrolled',
    'Synced',
    '{"source_department":"registrar","enrollment_status":"enrolled","section":"BSIT-3A","subject_load":["IT 301","IT 305","IT 309"]}'::jsonb,
    NOW()
  ),
  (
    '2026-0002',
    'Brian Santos',
    'BSHM',
    '2nd Year',
    'BSHM-2B',
    'Enrolled',
    'Synced',
    '{"source_department":"registrar","enrollment_status":"enrolled","section":"BSHM-2B","subject_load":["HM 201","HM 204"]}'::jsonb,
    NOW()
  ),
  (
    '2026-0003',
    'Camille Dela Cruz',
    'BSCS',
    '1st Year',
    'BSCS-1C',
    'Advised',
    'Pending Update',
    '{"source_department":"registrar","enrollment_status":"advised","section":"BSCS-1C","subject_load":["CS 101","MATH 101"]}'::jsonb,
    NOW()
  );

INSERT INTO guidance (
  student_name, student_id, concern, action_taken, date_recorded, status,
  case_reference, category, priority_level, referral_status, shared_with, synced_at
)
VALUES
  (
    'Alyssa Ramos',
    '2026-0001',
    'Student reported persistent anxiety during midterm week and requested structured academic support.',
    'Initial intake completed. Counselor recommended study-load adjustment request and weekly monitoring.',
    CURRENT_DATE - INTERVAL '6 day',
    'Pending',
    'GDN-2026-0001',
    'wellness',
    'medium',
    'shared',
    '["registrar","pmed"]'::jsonb,
    NOW()
  ),
  (
    'Brian Santos',
    '2026-0002',
    'Student requested behavior review after repeated attendance-related reminders from class advisers.',
    'Guidance documented concern and prepared intervention summary for Registrar follow-through.',
    CURRENT_DATE - INTERVAL '4 day',
    'Pending',
    'GDN-2026-0002',
    'behavior',
    'high',
    'shared',
    '["registrar","prefect"]'::jsonb,
    NOW()
  );

INSERT INTO counseling (
  student_name, student_id, issue, status, schedule_date, reason, reply,
  case_reference, risk_level, referral_status, source_department, shared_with, synced_at
)
VALUES
  (
    'Alyssa Ramos',
    '2026-0001',
    'Academic stress and recurring panic episodes during examinations.',
    'Replied',
    CURRENT_DATE + INTERVAL '2 day',
    'Student requested immediate counseling support.',
    'Scheduled for guided coping session and wellness monitoring.',
    'COU-2026-0001',
    'high',
    'shared',
    'guidance',
    '["registrar","pmed"]'::jsonb,
    NOW()
  ),
  (
    'Camille Dela Cruz',
    '2026-0003',
    'Transition concerns during first semester and request for peer adjustment support.',
    'Pending',
    CURRENT_DATE + INTERVAL '5 day',
    'Faculty adviser endorsed student for adjustment counseling.',
    NULL,
    'COU-2026-0002',
    'medium',
    'internal_review',
    'guidance',
    '["pmed"]'::jsonb,
    NOW()
  );

INSERT INTO crisis (
  student_name, student_id, incident, action_taken, date_reported,
  case_reference, severity_level, referral_status, shared_with, synced_at
)
VALUES
  (
    'Brian Santos',
    '2026-0002',
    'Escalated emotional distress after a conduct-related incident in class.',
    'Immediate de-escalation completed. Parent contact prepared and referral routed to Clinic and Prefect.',
    CURRENT_DATE - INTERVAL '2 day',
    'CRI-2026-0001',
    'urgent',
    'shared',
    '["clinic","prefect","pmed"]'::jsonb,
    NOW()
  ),
  (
    'Alyssa Ramos',
    '2026-0001',
    'Student experienced dizziness and panic symptoms after a stressful presentation.',
    'Guidance logged health-related concern and prepared Clinic handoff.',
    CURRENT_DATE - INTERVAL '1 day',
    'CRI-2026-0002',
    'high',
    'shared',
    '["clinic","pmed"]'::jsonb,
    NOW()
  );

INSERT INTO survey (
  student_name, feedback, rating, date_submitted, status, reviewed_by, student_id,
  source_channel, escalation_status, shared_with
)
VALUES
  (
    'Alyssa Ramos',
    'The counseling response helped, but I still need follow-up support for exam weeks.',
    4,
    CURRENT_DATE - INTERVAL '3 day',
    'Reviewed',
    'Guidance Admin',
    '2026-0001',
    'web',
    'monitoring',
    '["guidance","pmed"]'::jsonb
  ),
  (
    'Brian Santos',
    'I want clearer communication between Guidance and discipline handling so I know the next steps.',
    3,
    CURRENT_DATE - INTERVAL '2 day',
    'Pending',
    NULL,
    '2026-0002',
    'kiosk',
    'follow_up',
    '["guidance","prefect"]'::jsonb
  );

INSERT INTO integration_departments (
  department_key, department_name, system_code, module_directory, purpose, default_action_label,
  dispatch_rpc_name, status_rpc_name, ack_rpc_name, dispatch_endpoint, is_master_data_provider, is_active
)
VALUES
  (
    'guidance',
    'Guidance Office',
    'GUIDANCE',
    'guidance-system',
    'Counseling, incidents, referrals, and behavioral monitoring.',
    'Queue Guidance Event',
    'dispatch_department_flow',
    'get_department_flow_status',
    'acknowledge_department_flow',
    'supabase',
    FALSE,
    TRUE
  ),
  (
    'registrar',
    'Registrar',
    'REGISTRAR',
    'Registrar',
    'Student master profile, enrollment status, courses, sections, and subject load.',
    'Sync Student Master Data',
    'dispatch_department_flow',
    'get_department_flow_status',
    'acknowledge_department_flow',
    'supabase',
    TRUE,
    TRUE
  ),
  (
    'prefect',
    'Prefect Management',
    'PREFECT',
    'PrefectManagementSystem',
    'Violations, offense reports, sanctions, and conduct status.',
    'Escalate to Prefect',
    'dispatch_department_flow',
    'get_department_flow_status',
    'acknowledge_department_flow',
    'supabase',
    FALSE,
    TRUE
  ),
  (
    'pmed',
    'PMED',
    'PMED',
    'PMED',
    'Health and incident monitoring, dashboards, reports, and clearance visibility.',
    'Send Monitoring Update',
    'dispatch_department_flow',
    'get_department_flow_status',
    'acknowledge_department_flow',
    'supabase',
    FALSE,
    TRUE
  );

INSERT INTO integration_routes (
  route_key, source_department_key, target_department_key, flow_name, event_code,
  endpoint_path, priority, is_required, is_active, default_payload
)
VALUES
  (
    'guidance_to_registrar_counseling_report',
    'guidance',
    'registrar',
    'Guidance Counseling Report',
    'counseling_report',
    'supabase',
    10,
    TRUE,
    TRUE,
    '{"case_type":"counseling","source":"guidance"}'::jsonb
  ),
  (
    'guidance_to_pmed_counseling_report',
    'guidance',
    'pmed',
    'Guidance to PMED Counseling Report',
    'counseling_report',
    'supabase',
    20,
    TRUE,
    TRUE,
    '{"case_type":"counseling","source":"guidance"}'::jsonb
  ),
  (
    'guidance_to_registrar_student_case_summary',
    'guidance',
    'registrar',
    'Student Case Summary to Registrar',
    'student_case_summary',
    'supabase',
    14,
    FALSE,
    TRUE,
    '{"case_type":"guidance_record","source":"guidance"}'::jsonb
  ),
  (
    'guidance_to_prefect_behavior_referral',
    'guidance',
    'prefect',
    'Behavior Referral to Prefect',
    'behavior_referral',
    'supabase',
    9,
    TRUE,
    TRUE,
    '{"case_type":"guidance_record","source":"guidance"}'::jsonb
  ),
  (
    'guidance_to_prefect_discipline_report',
    'guidance',
    'prefect',
    'Discipline Report to Prefect',
    'discipline_report',
    'supabase',
    8,
    TRUE,
    TRUE,
    '{"case_type":"crisis","source":"guidance"}'::jsonb
  ),
  (
    'guidance_to_pmed_incident_monitoring_update',
    'guidance',
    'pmed',
    'Incident Monitoring Update to PMED',
    'incident_monitoring_update',
    'supabase',
    12,
    TRUE,
    TRUE,
    '{"case_type":"crisis","source":"guidance"}'::jsonb
  ),
  (
    'registrar_to_guidance_student_profile_sync',
    'registrar',
    'guidance',
    'Student Profile Sync to Guidance',
    'student_profile_sync',
    'supabase',
    15,
    TRUE,
    TRUE,
    '{"source":"registrar","entity":"student"}'::jsonb
  ),
  (
    'guidance_to_pmed_wellness_summary',
    'guidance',
    'pmed',
    'Wellness Summary to PMED',
    'wellness_summary',
    'supabase',
    16,
    FALSE,
    TRUE,
    '{"case_type":"guidance_record","source":"guidance"}'::jsonb
  ),
  (
    'prefect_to_guidance_offense_report',
    'prefect',
    'guidance',
    'Prefect Offense Report',
    'offense_report',
    'supabase',
    10,
    TRUE,
    TRUE,
    '{"source":"prefect","entity":"offense"}'::jsonb
  ),
  (
    'pmed_to_guidance_monitoring_summary',
    'pmed',
    'guidance',
    'PMED Monitoring Summary',
    'monitoring_summary',
    'supabase',
    22,
    FALSE,
    TRUE,
    '{"source":"pmed","entity":"monitoring"}'::jsonb
  );

INSERT INTO integration_flows (
  direction, source_department, target_department, flow_type, reference_table, reference_id,
  student_id, student_name, payload_summary, status, received_at, acknowledged_at, sent_at, created_at,
  route_key, event_code, correlation_id, source_record_table, source_record_id, payload_json, last_error, updated_at
)
VALUES
  (
    'OUTBOUND',
    'guidance',
    'registrar',
    'Counseling Report',
    'counseling',
    (SELECT id FROM counseling WHERE case_reference = 'COU-2026-0001' LIMIT 1),
    '2026-0001',
    'Alyssa Ramos',
    'Counseling summary queued for academic support coordination with Registrar.',
    'Sent',
    NULL,
    NULL,
    NOW() - INTERVAL '2 day',
    NOW() - INTERVAL '2 day',
    'guidance_to_registrar_counseling_report',
    'counseling_report',
    'seed-guidance-registrar-0001',
    'counseling',
    (SELECT id FROM counseling WHERE case_reference = 'COU-2026-0001' LIMIT 1),
    '{"risk_level":"high","shared_with":["registrar"],"case_reference":"COU-2026-0001"}'::jsonb,
    NULL,
    NOW() - INTERVAL '2 day'
  ),
  (
    'OUTBOUND',
    'guidance',
    'pmed',
    'Incident Monitoring Update',
    'crisis',
    (SELECT id FROM crisis WHERE case_reference = 'CRI-2026-0001' LIMIT 1),
    '2026-0002',
    'Brian Santos',
    'Crisis incident forwarded to PMED for consolidated monitoring.',
    'Queued',
    NULL,
    NULL,
    NULL,
    NOW() - INTERVAL '1 day',
    'guidance_to_pmed_incident_monitoring_update',
    'incident_monitoring_update',
    'seed-guidance-pmed-0002',
    'crisis',
    (SELECT id FROM crisis WHERE case_reference = 'CRI-2026-0001' LIMIT 1),
    '{"severity":"urgent","shared_with":["pmed","prefect","clinic"],"case_reference":"CRI-2026-0001"}'::jsonb,
    NULL,
    NOW() - INTERVAL '1 day'
  ),
  (
    'INBOUND',
    'pmed',
    'guidance',
    'Monitoring Summary',
    NULL,
    NULL,
    '2026-0001',
    'Alyssa Ramos',
    'PMED advised continued monitoring for stress-related symptoms and weekly counseling follow-up.',
    'Acknowledged',
    NOW() - INTERVAL '12 hour',
    NOW() - INTERVAL '10 hour',
    NULL,
    NOW() - INTERVAL '12 hour',
    'pmed_to_guidance_monitoring_summary',
    'monitoring_summary',
    'seed-pmed-guidance-0003',
    NULL,
    NULL,
    '{"monitoring_level":"weekly","next_step":"guidance_follow_up"}'::jsonb,
    NULL,
    NOW() - INTERVAL '10 hour'
  ),
  (
    'INBOUND',
    'prefect',
    'guidance',
    'Offense Report',
    NULL,
    NULL,
    '2026-0002',
    'Brian Santos',
    'Prefect endorsed conduct incident for counseling-based intervention.',
    'Received',
    NOW() - INTERVAL '6 hour',
    NULL,
    NULL,
    NOW() - INTERVAL '6 hour',
    'prefect_to_guidance_offense_report',
    'offense_report',
    'seed-prefect-guidance-0004',
    NULL,
    NULL,
    '{"offense_level":"major","recommended_action":"joint_case_review"}'::jsonb,
    NULL,
    NOW() - INTERVAL '6 hour'
  );

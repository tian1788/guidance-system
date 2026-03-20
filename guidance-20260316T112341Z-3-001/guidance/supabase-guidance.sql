-- Split department schema generated from supabase/schema.sql.
-- Run files in numeric order inside this folder.
-- clinic must run before pmed and cashier because they expose clinic-owned shared tables as views.
-- Source: guidance-system/guidance-20260316T112341Z-3-001/guidance/guidance_db.sql (converted from MySQL)
-- Namespace: guidance
-- ======================================================================

CREATE SCHEMA IF NOT EXISTS guidance;
SET search_path TO guidance, public;

-- Guidance System core tables (merge-safe, PostgreSQL/Supabase)
CREATE TABLE IF NOT EXISTS guidance.users (
  id BIGSERIAL PRIMARY KEY,
  name VARCHAR(100) NULL,
  email VARCHAR(100) NULL,
  password VARCHAR(100) NULL,
  role VARCHAR(20) NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS guidance.students (
  id BIGSERIAL PRIMARY KEY,
  student_id VARCHAR(50) NULL,
  name VARCHAR(100) NULL,
  course VARCHAR(100) NULL,
  year_level VARCHAR(50) NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE guidance.students ADD COLUMN IF NOT EXISTS section_name VARCHAR(100) NULL;
ALTER TABLE guidance.students ADD COLUMN IF NOT EXISTS enrollment_status VARCHAR(50) NULL DEFAULT 'Active';
ALTER TABLE guidance.students ADD COLUMN IF NOT EXISTS registrar_status VARCHAR(50) NULL DEFAULT 'Synced';
ALTER TABLE guidance.students ADD COLUMN IF NOT EXISTS external_profile JSONB NULL;
ALTER TABLE guidance.students ADD COLUMN IF NOT EXISTS synced_at TIMESTAMPTZ NULL;

CREATE TABLE IF NOT EXISTS guidance.guidance (
  id BIGSERIAL PRIMARY KEY,
  student_name VARCHAR(100) NULL,
  concern TEXT NULL,
  action_taken TEXT NULL,
  date_recorded DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Pending',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE guidance.guidance ADD COLUMN IF NOT EXISTS student_id VARCHAR(50) NULL;
ALTER TABLE guidance.guidance ADD COLUMN IF NOT EXISTS case_reference VARCHAR(80) NULL;
ALTER TABLE guidance.guidance ADD COLUMN IF NOT EXISTS category VARCHAR(50) NULL DEFAULT 'general';
ALTER TABLE guidance.guidance ADD COLUMN IF NOT EXISTS priority_level VARCHAR(20) NULL DEFAULT 'medium';
ALTER TABLE guidance.guidance ADD COLUMN IF NOT EXISTS referral_status VARCHAR(30) NULL DEFAULT 'internal_review';
ALTER TABLE guidance.guidance ADD COLUMN IF NOT EXISTS shared_with JSONB NULL;
ALTER TABLE guidance.guidance ADD COLUMN IF NOT EXISTS synced_at TIMESTAMPTZ NULL;

CREATE TABLE IF NOT EXISTS guidance.counseling (
  id BIGSERIAL PRIMARY KEY,
  student_name VARCHAR(100) NULL,
  student_id VARCHAR(50) NULL,
  issue TEXT NULL,
  status VARCHAR(50) NULL,
  schedule_date DATE NULL,
  reason TEXT NULL,
  reply TEXT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE guidance.counseling ADD COLUMN IF NOT EXISTS case_reference VARCHAR(80) NULL;
ALTER TABLE guidance.counseling ADD COLUMN IF NOT EXISTS risk_level VARCHAR(20) NULL DEFAULT 'medium';
ALTER TABLE guidance.counseling ADD COLUMN IF NOT EXISTS referral_status VARCHAR(30) NULL DEFAULT 'internal_review';
ALTER TABLE guidance.counseling ADD COLUMN IF NOT EXISTS source_department VARCHAR(50) NULL DEFAULT 'guidance';
ALTER TABLE guidance.counseling ADD COLUMN IF NOT EXISTS shared_with JSONB NULL;
ALTER TABLE guidance.counseling ADD COLUMN IF NOT EXISTS synced_at TIMESTAMPTZ NULL;

CREATE TABLE IF NOT EXISTS guidance.crisis (
  id BIGSERIAL PRIMARY KEY,
  student_name VARCHAR(100) NULL,
  incident TEXT NULL,
  action_taken TEXT NULL,
  date_reported DATE NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE guidance.crisis ADD COLUMN IF NOT EXISTS student_id VARCHAR(50) NULL;
ALTER TABLE guidance.crisis ADD COLUMN IF NOT EXISTS case_reference VARCHAR(80) NULL;
ALTER TABLE guidance.crisis ADD COLUMN IF NOT EXISTS severity_level VARCHAR(20) NULL DEFAULT 'high';
ALTER TABLE guidance.crisis ADD COLUMN IF NOT EXISTS referral_status VARCHAR(30) NULL DEFAULT 'internal_review';
ALTER TABLE guidance.crisis ADD COLUMN IF NOT EXISTS shared_with JSONB NULL;
ALTER TABLE guidance.crisis ADD COLUMN IF NOT EXISTS synced_at TIMESTAMPTZ NULL;

CREATE TABLE IF NOT EXISTS guidance.survey (
  id BIGSERIAL PRIMARY KEY,
  student_name VARCHAR(100) NULL,
  feedback TEXT NULL,
  rating INT NULL,
  date_submitted DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Pending' CHECK (status IN ('Pending', 'Reviewed')),
  reviewed_by VARCHAR(100) NULL,
  student_id VARCHAR(20) NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE guidance.survey ADD COLUMN IF NOT EXISTS source_channel VARCHAR(50) NULL DEFAULT 'web';
ALTER TABLE guidance.survey ADD COLUMN IF NOT EXISTS escalation_status VARCHAR(30) NULL DEFAULT 'none';
ALTER TABLE guidance.survey ADD COLUMN IF NOT EXISTS shared_with JSONB NULL;

CREATE TABLE IF NOT EXISTS guidance.integration_departments (
  department_key VARCHAR(50) PRIMARY KEY,
  department_name VARCHAR(120) NOT NULL,
  system_code VARCHAR(50) NOT NULL,
  module_directory VARCHAR(120) NULL,
  purpose TEXT NULL,
  default_action_label VARCHAR(120) NULL,
  dispatch_rpc_name VARCHAR(120) NULL,
  status_rpc_name VARCHAR(120) NULL,
  ack_rpc_name VARCHAR(120) NULL,
  dispatch_endpoint VARCHAR(200) NULL,
  is_master_data_provider BOOLEAN NOT NULL DEFAULT FALSE,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS guidance.integration_routes (
  route_key VARCHAR(120) PRIMARY KEY,
  source_department_key VARCHAR(50) NOT NULL REFERENCES guidance.integration_departments(department_key) ON DELETE CASCADE,
  target_department_key VARCHAR(50) NOT NULL REFERENCES guidance.integration_departments(department_key) ON DELETE CASCADE,
  flow_name VARCHAR(150) NOT NULL,
  event_code VARCHAR(120) NOT NULL,
  endpoint_path VARCHAR(200) NULL,
  priority INT NOT NULL DEFAULT 100,
  is_required BOOLEAN NOT NULL DEFAULT FALSE,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  default_payload JSONB NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE OR REPLACE FUNCTION guidance.set_updated_at_timestamp()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
  NEW.updated_at = NOW();
  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_guidance_users_updated_at ON guidance.users;
CREATE TRIGGER trg_guidance_users_updated_at
BEFORE UPDATE ON guidance.users
FOR EACH ROW
EXECUTE FUNCTION guidance.set_updated_at_timestamp();

DROP TRIGGER IF EXISTS trg_guidance_students_updated_at ON guidance.students;
CREATE TRIGGER trg_guidance_students_updated_at
BEFORE UPDATE ON guidance.students
FOR EACH ROW
EXECUTE FUNCTION guidance.set_updated_at_timestamp();

DROP TRIGGER IF EXISTS trg_guidance_guidance_updated_at ON guidance.guidance;
CREATE TRIGGER trg_guidance_guidance_updated_at
BEFORE UPDATE ON guidance.guidance
FOR EACH ROW
EXECUTE FUNCTION guidance.set_updated_at_timestamp();

DROP TRIGGER IF EXISTS trg_guidance_counseling_updated_at ON guidance.counseling;
CREATE TRIGGER trg_guidance_counseling_updated_at
BEFORE UPDATE ON guidance.counseling
FOR EACH ROW
EXECUTE FUNCTION guidance.set_updated_at_timestamp();

DROP TRIGGER IF EXISTS trg_guidance_crisis_updated_at ON guidance.crisis;
CREATE TRIGGER trg_guidance_crisis_updated_at
BEFORE UPDATE ON guidance.crisis
FOR EACH ROW
EXECUTE FUNCTION guidance.set_updated_at_timestamp();

DROP TRIGGER IF EXISTS trg_guidance_survey_updated_at ON guidance.survey;
CREATE TRIGGER trg_guidance_survey_updated_at
BEFORE UPDATE ON guidance.survey
FOR EACH ROW
EXECUTE FUNCTION guidance.set_updated_at_timestamp();

DROP TRIGGER IF EXISTS trg_guidance_integration_departments_updated_at ON guidance.integration_departments;
CREATE TRIGGER trg_guidance_integration_departments_updated_at
BEFORE UPDATE ON guidance.integration_departments
FOR EACH ROW
EXECUTE FUNCTION guidance.set_updated_at_timestamp();

DROP TRIGGER IF EXISTS trg_guidance_integration_routes_updated_at ON guidance.integration_routes;
CREATE TRIGGER trg_guidance_integration_routes_updated_at
BEFORE UPDATE ON guidance.integration_routes
FOR EACH ROW
EXECUTE FUNCTION guidance.set_updated_at_timestamp();

CREATE INDEX IF NOT EXISTS idx_guidance_users_email ON guidance.users(email);
CREATE INDEX IF NOT EXISTS idx_guidance_users_role ON guidance.users(role);
CREATE INDEX IF NOT EXISTS idx_guidance_students_student_id ON guidance.students(student_id);
CREATE INDEX IF NOT EXISTS idx_guidance_students_enrollment_status ON guidance.students(enrollment_status);
CREATE INDEX IF NOT EXISTS idx_guidance_guidance_student_name ON guidance.guidance(student_name);
CREATE INDEX IF NOT EXISTS idx_guidance_guidance_student_id ON guidance.guidance(student_id);
CREATE INDEX IF NOT EXISTS idx_guidance_guidance_date_recorded ON guidance.guidance(date_recorded DESC);
CREATE INDEX IF NOT EXISTS idx_guidance_counseling_student_id ON guidance.counseling(student_id);
CREATE INDEX IF NOT EXISTS idx_guidance_counseling_status ON guidance.counseling(status);
CREATE INDEX IF NOT EXISTS idx_guidance_counseling_case_reference ON guidance.counseling(case_reference);
CREATE INDEX IF NOT EXISTS idx_guidance_crisis_date_reported ON guidance.crisis(date_reported DESC);
CREATE INDEX IF NOT EXISTS idx_guidance_crisis_student_id ON guidance.crisis(student_id);
CREATE INDEX IF NOT EXISTS idx_guidance_survey_student_id ON guidance.survey(student_id);
CREATE INDEX IF NOT EXISTS idx_guidance_survey_status ON guidance.survey(status);
CREATE UNIQUE INDEX IF NOT EXISTS uq_guidance_users_email ON guidance.users(email) WHERE email IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_guidance_students_student_id ON guidance.students(student_id) WHERE student_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_guidance_guidance_case_reference ON guidance.guidance(case_reference) WHERE case_reference IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_guidance_counseling_case_reference ON guidance.counseling(case_reference) WHERE case_reference IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_guidance_crisis_case_reference ON guidance.crisis(case_reference) WHERE case_reference IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_guidance_integration_departments_active ON guidance.integration_departments(is_active);
CREATE INDEX IF NOT EXISTS idx_guidance_integration_routes_source_target ON guidance.integration_routes(source_department_key, target_department_key);
CREATE INDEX IF NOT EXISTS idx_guidance_integration_routes_event_code ON guidance.integration_routes(event_code);

CREATE TABLE IF NOT EXISTS guidance.integration_flows (
  id BIGSERIAL PRIMARY KEY,
  direction VARCHAR(20) NOT NULL CHECK (direction IN ('INBOUND', 'OUTBOUND')),
  source_department VARCHAR(50) NOT NULL,
  target_department VARCHAR(50) NOT NULL,
  flow_type VARCHAR(80) NOT NULL,
  reference_table VARCHAR(50) NULL,
  reference_id BIGINT NULL,
  student_id VARCHAR(50) NULL,
  student_name VARCHAR(100) NULL,
  payload_summary TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Queued' CHECK (status IN ('Queued', 'Received', 'Acknowledged', 'Sent', 'Failed')),
  received_at TIMESTAMPTZ NULL,
  acknowledged_at TIMESTAMPTZ NULL,
  sent_at TIMESTAMPTZ NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_guidance_integration_direction_status ON guidance.integration_flows(direction, status);
CREATE INDEX IF NOT EXISTS idx_guidance_integration_created_at ON guidance.integration_flows(created_at DESC);
ALTER TABLE guidance.integration_flows ADD COLUMN IF NOT EXISTS route_key VARCHAR(120) NULL;
ALTER TABLE guidance.integration_flows ADD COLUMN IF NOT EXISTS event_code VARCHAR(120) NULL;
ALTER TABLE guidance.integration_flows ADD COLUMN IF NOT EXISTS correlation_id VARCHAR(120) NULL;
ALTER TABLE guidance.integration_flows ADD COLUMN IF NOT EXISTS source_record_table VARCHAR(80) NULL;
ALTER TABLE guidance.integration_flows ADD COLUMN IF NOT EXISTS source_record_id BIGINT NULL;
ALTER TABLE guidance.integration_flows ADD COLUMN IF NOT EXISTS payload_json JSONB NULL;
ALTER TABLE guidance.integration_flows ADD COLUMN IF NOT EXISTS response_payload JSONB NULL;
ALTER TABLE guidance.integration_flows ADD COLUMN IF NOT EXISTS last_error TEXT NULL;
ALTER TABLE guidance.integration_flows ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
CREATE INDEX IF NOT EXISTS idx_guidance_integration_route_key ON guidance.integration_flows(route_key);
CREATE INDEX IF NOT EXISTS idx_guidance_integration_event_code ON guidance.integration_flows(event_code);
CREATE INDEX IF NOT EXISTS idx_guidance_integration_correlation_id ON guidance.integration_flows(correlation_id);
CREATE INDEX IF NOT EXISTS idx_guidance_integration_source_target ON guidance.integration_flows(source_department, target_department);

CREATE OR REPLACE FUNCTION guidance.dispatch_department_flow(
  _source_department_key TEXT,
  _target_department_key TEXT,
  _event_code TEXT,
  _source_record_id BIGINT DEFAULT NULL,
  _payload JSONB DEFAULT '{}'::JSONB,
  _requested_by TEXT DEFAULT NULL
)
RETURNS JSONB
LANGUAGE plpgsql
AS $$
DECLARE
  v_route guidance.integration_routes%ROWTYPE;
  v_existing guidance.integration_flows%ROWTYPE;
  v_correlation_id TEXT;
  v_route_key TEXT;
  v_flow_name TEXT;
  v_student_id TEXT;
  v_student_name TEXT;
  v_summary TEXT;
  v_reference_table TEXT;
  v_outbound_id BIGINT;
BEGIN
  IF COALESCE(TRIM(_source_department_key), '') = '' OR COALESCE(TRIM(_target_department_key), '') = '' OR COALESCE(TRIM(_event_code), '') = '' THEN
    RETURN jsonb_build_object('ok', false, 'message', 'source, target, and event_code are required.');
  END IF;

  SELECT *
  INTO v_route
  FROM guidance.integration_routes
  WHERE source_department_key = LOWER(TRIM(_source_department_key))
    AND target_department_key = LOWER(TRIM(_target_department_key))
    AND event_code = TRIM(_event_code)
    AND is_active = TRUE
  ORDER BY priority ASC
  LIMIT 1;

  v_correlation_id := COALESCE(NULLIF(_payload->>'correlation_id', ''), md5(random()::TEXT || clock_timestamp()::TEXT || _source_department_key || _target_department_key || _event_code));
  v_route_key := COALESCE(v_route.route_key, LOWER(TRIM(_source_department_key)) || '_to_' || LOWER(TRIM(_target_department_key)) || '_' || TRIM(_event_code));
  v_flow_name := COALESCE(v_route.flow_name, INITCAP(REPLACE(TRIM(_event_code), '_', ' ')));
  v_student_id := NULLIF(COALESCE(_payload->>'student_id', ''), '');
  v_student_name := NULLIF(COALESCE(_payload->>'student_name', _payload->>'name', ''), '');
  v_summary := NULLIF(COALESCE(_payload->>'summary', _payload->>'issue', _payload->>'incident', _payload->>'concern', _payload->>'report_summary', ''), '');
  v_reference_table := NULLIF(COALESCE(_payload->>'reference_table', _payload->>'source_record_table', ''), '');

  SELECT *
  INTO v_existing
  FROM guidance.integration_flows
  WHERE correlation_id = v_correlation_id
    AND direction = 'OUTBOUND'
    AND source_department = LOWER(TRIM(_source_department_key))
    AND target_department = LOWER(TRIM(_target_department_key))
  ORDER BY id DESC
  LIMIT 1;

  IF FOUND THEN
    RETURN jsonb_build_object(
      'ok', true,
      'event_id', v_existing.id::TEXT,
      'correlation_id', v_existing.correlation_id,
      'route_key', v_existing.route_key,
      'source_department_key', v_existing.source_department,
      'target_department_key', v_existing.target_department,
      'event_code', v_existing.event_code,
      'status', v_existing.status,
      'dispatch_endpoint', 'supabase'
    );
  END IF;

  INSERT INTO guidance.integration_flows (
    direction, source_department, target_department, flow_type, reference_table, reference_id,
    student_id, student_name, payload_summary, status, sent_at, route_key, event_code, correlation_id,
    source_record_table, source_record_id, payload_json, updated_at
  ) VALUES (
    'OUTBOUND',
    LOWER(TRIM(_source_department_key)),
    LOWER(TRIM(_target_department_key)),
    v_flow_name,
    v_reference_table,
    _source_record_id,
    v_student_id,
    v_student_name,
    v_summary,
    'Sent',
    NOW(),
    v_route_key,
    TRIM(_event_code),
    v_correlation_id,
    v_reference_table,
    _source_record_id,
    COALESCE(_payload, '{}'::JSONB),
    NOW()
  )
  RETURNING id INTO v_outbound_id;

  INSERT INTO guidance.integration_flows (
    direction, source_department, target_department, flow_type, reference_table, reference_id,
    student_id, student_name, payload_summary, status, received_at, route_key, event_code, correlation_id,
    source_record_table, source_record_id, payload_json, updated_at
  ) VALUES (
    'INBOUND',
    LOWER(TRIM(_source_department_key)),
    LOWER(TRIM(_target_department_key)),
    v_flow_name,
    v_reference_table,
    _source_record_id,
    v_student_id,
    v_student_name,
    v_summary,
    'Received',
    NOW(),
    v_route_key,
    TRIM(_event_code),
    v_correlation_id,
    v_reference_table,
    _source_record_id,
    COALESCE(_payload, '{}'::JSONB),
    NOW()
  );

  RETURN jsonb_build_object(
    'ok', true,
    'event_id', v_outbound_id::TEXT,
    'correlation_id', v_correlation_id,
    'route_key', v_route_key,
    'source_department_key', LOWER(TRIM(_source_department_key)),
    'target_department_key', LOWER(TRIM(_target_department_key)),
    'event_code', TRIM(_event_code),
    'status', 'Sent',
    'dispatch_endpoint', 'supabase'
  );
END;
$$;

CREATE OR REPLACE FUNCTION guidance.get_department_flow_status(
  _event_id BIGINT DEFAULT NULL,
  _correlation_id TEXT DEFAULT NULL
)
RETURNS JSONB
LANGUAGE plpgsql
AS $$
DECLARE
  v_event guidance.integration_flows%ROWTYPE;
BEGIN
  IF _event_id IS NULL AND COALESCE(TRIM(_correlation_id), '') = '' THEN
    RETURN jsonb_build_object('ok', false, 'last_error', 'event_id or correlation_id is required.');
  END IF;

  SELECT *
  INTO v_event
  FROM guidance.integration_flows
  WHERE (_event_id IS NOT NULL AND id = _event_id)
     OR (_event_id IS NULL AND correlation_id = TRIM(_correlation_id))
  ORDER BY CASE WHEN direction = 'OUTBOUND' THEN 0 ELSE 1 END, id DESC
  LIMIT 1;

  IF NOT FOUND THEN
    RETURN jsonb_build_object('ok', false, 'last_error', 'Integration event not found.');
  END IF;

  RETURN jsonb_build_object(
    'ok', true,
    'event_id', v_event.id::TEXT,
    'correlation_id', v_event.correlation_id,
    'route_key', v_event.route_key,
    'flow_name', v_event.flow_type,
    'source_department_key', v_event.source_department,
    'target_department_key', v_event.target_department,
    'event_code', v_event.event_code,
    'status', v_event.status,
    'request_payload', v_event.payload_json,
    'response_payload', v_event.response_payload,
    'last_error', v_event.last_error,
    'dispatched_at', v_event.sent_at,
    'acknowledged_at', v_event.acknowledged_at,
    'created_at', v_event.created_at,
    'updated_at', v_event.updated_at
  );
END;
$$;

CREATE OR REPLACE FUNCTION guidance.acknowledge_department_flow(
  _event_id BIGINT,
  _status TEXT DEFAULT 'acknowledged',
  _response JSONB DEFAULT '{}'::JSONB,
  _error TEXT DEFAULT NULL
)
RETURNS JSONB
LANGUAGE plpgsql
AS $$
DECLARE
  v_event guidance.integration_flows%ROWTYPE;
  v_normalized_status TEXT;
BEGIN
  SELECT *
  INTO v_event
  FROM guidance.integration_flows
  WHERE id = _event_id
  LIMIT 1;

  IF NOT FOUND THEN
    RETURN jsonb_build_object('ok', false, 'message', 'Integration event not found.');
  END IF;

  v_normalized_status := INITCAP(LOWER(COALESCE(NULLIF(TRIM(_status), ''), 'acknowledged')));

  UPDATE guidance.integration_flows
  SET status = v_normalized_status,
      acknowledged_at = CASE WHEN v_normalized_status IN ('Acknowledged', 'Completed') THEN NOW() ELSE acknowledged_at END,
      response_payload = COALESCE(_response, '{}'::JSONB),
      last_error = NULLIF(_error, ''),
      updated_at = NOW()
  WHERE correlation_id = v_event.correlation_id;

  RETURN jsonb_build_object(
    'ok', true,
    'message', 'Integration event updated.',
    'event_id', v_event.id::TEXT,
    'correlation_id', v_event.correlation_id,
    'status', v_normalized_status
  );
END;
$$;

CREATE OR REPLACE FUNCTION guidance.get_department_integration_registry(
  _source_department_key TEXT DEFAULT NULL
)
RETURNS JSONB
LANGUAGE plpgsql
AS $$
DECLARE
  v_registry JSONB;
BEGIN
  SELECT COALESCE(
    jsonb_agg(
      jsonb_build_object(
        'department_key', d.department_key,
        'department_name', d.department_name,
        'system_code', d.system_code,
        'module_directory', d.module_directory,
        'purpose', d.purpose,
        'default_action_label', d.default_action_label,
        'dispatch_rpc_name', d.dispatch_rpc_name,
        'status_rpc_name', d.status_rpc_name,
        'ack_rpc_name', d.ack_rpc_name,
        'dispatch_endpoint', 'supabase',
        'pending_count', COALESCE((
          SELECT COUNT(*) FROM guidance.integration_flows f
          WHERE f.target_department = d.department_key AND f.direction = 'INBOUND' AND f.status = 'Received'
        ), 0),
        'in_progress_count', 0,
        'failed_count', COALESCE((
          SELECT COUNT(*) FROM guidance.integration_flows f
          WHERE f.target_department = d.department_key AND f.status = 'Failed'
        ), 0),
        'completed_count', COALESCE((
          SELECT COUNT(*) FROM guidance.integration_flows f
          WHERE f.target_department = d.department_key AND f.status IN ('Acknowledged', 'Completed', 'Sent')
        ), 0),
        'route_count', COALESCE((
          SELECT COUNT(*) FROM guidance.integration_routes r
          WHERE r.target_department_key = d.department_key
            AND r.is_active = TRUE
            AND (_source_department_key IS NULL OR r.source_department_key = _source_department_key)
        ), 0),
        'latest_status', (
          SELECT f.status FROM guidance.integration_flows f
          WHERE f.target_department = d.department_key OR f.source_department = d.department_key
          ORDER BY f.created_at DESC, f.id DESC
          LIMIT 1
        ),
        'latest_event_code', (
          SELECT f.event_code FROM guidance.integration_flows f
          WHERE f.target_department = d.department_key OR f.source_department = d.department_key
          ORDER BY f.created_at DESC, f.id DESC
          LIMIT 1
        ),
        'latest_correlation_id', (
          SELECT f.correlation_id FROM guidance.integration_flows f
          WHERE f.target_department = d.department_key OR f.source_department = d.department_key
          ORDER BY f.created_at DESC, f.id DESC
          LIMIT 1
        ),
        'latest_created_at', (
          SELECT f.created_at FROM guidance.integration_flows f
          WHERE f.target_department = d.department_key OR f.source_department = d.department_key
          ORDER BY f.created_at DESC, f.id DESC
          LIMIT 1
        ),
        'routes', COALESCE((
          SELECT jsonb_agg(
            jsonb_build_object(
              'route_key', r.route_key,
              'flow_name', r.flow_name,
              'event_code', r.event_code,
              'endpoint_path', 'supabase',
              'priority', r.priority,
              'is_required', r.is_required
            )
            ORDER BY r.priority ASC
          )
          FROM guidance.integration_routes r
          WHERE r.target_department_key = d.department_key
            AND r.is_active = TRUE
            AND (_source_department_key IS NULL OR r.source_department_key = _source_department_key)
        ), '[]'::JSONB)
      )
    ),
    '[]'::JSONB
  )
  INTO v_registry
  FROM guidance.integration_departments d
  WHERE d.is_active = TRUE
    AND (
      _source_department_key IS NULL
      OR d.department_key = _source_department_key
      OR EXISTS (
        SELECT 1
        FROM guidance.integration_routes r
        WHERE r.target_department_key = d.department_key
          AND r.source_department_key = _source_department_key
          AND r.is_active = TRUE
      )
    );

  RETURN v_registry;
END;
$$;

SET search_path TO public;

<?php

function guidance_integration_departments(): array
{
    return [
        'registrar' => [
            'label' => 'Registrar',
            'direction' => 'bidirectional',
            'description' => 'Master source of student identity, enrollment, course, year, section, and academic load.',
            'outbound_flows' => [
                'student_case_summary' => 'Student Case Summary',
                'student_behavior_update' => 'Student Behavior Update',
                'counseling_report' => 'Counseling Report',
            ],
            'inbound_flows' => [
                'student_profile_sync' => 'Student Profile Sync',
                'enrollment_status_sync' => 'Enrollment Status Sync',
                'class_assignment_sync' => 'Class Assignment Sync',
            ],
        ],
        'prefect' => [
            'label' => 'Prefect Management',
            'direction' => 'bidirectional',
            'description' => 'Discipline, violations, sanctions, and conduct monitoring partner.',
            'outbound_flows' => [
                'discipline_report' => 'Discipline Report',
                'behavior_referral' => 'Behavior Referral',
            ],
            'inbound_flows' => [
                'offense_report' => 'Offense Report',
                'sanction_status_update' => 'Sanction Status Update',
                'behavior_status_update' => 'Behavior Status Update',
            ],
        ],
        'pmed' => [
            'label' => 'PMED',
            'direction' => 'bidirectional',
            'description' => 'Consolidated monitoring partner for counseling, incidents, referrals, and student wellness follow-through.',
            'outbound_flows' => [
                'counseling_report' => 'Counseling Report',
                'incident_monitoring_update' => 'Incident Monitoring Update',
                'wellness_summary' => 'Wellness Summary',
            ],
            'inbound_flows' => [
                'monitoring_summary' => 'Monitoring Summary',
                'clearance_status_update' => 'Clearance Status Update',
                'incident_dashboard_feedback' => 'Incident Dashboard Feedback',
            ],
        ],
    ];
}

function guidance_integration_default_routes(): array
{
    return [
        'students' => [
            [
                'route_key' => 'registrar_to_guidance_student_profile_sync',
                'target_department' => 'registrar',
                'event_code' => 'student_profile_sync',
                'label' => 'Receive from Registrar',
            ],
        ],
        'counseling' => [
            [
                'route_key' => 'guidance_to_registrar_counseling_report',
                'target_department' => 'registrar',
                'event_code' => 'counseling_report',
                'label' => 'Sync to Registrar',
            ],
            [
                'route_key' => 'guidance_to_pmed_counseling_report',
                'target_department' => 'pmed',
                'event_code' => 'counseling_report',
                'label' => 'Sync to PMED',
            ],
        ],
        'guidance' => [
            [
                'route_key' => 'guidance_to_registrar_student_case_summary',
                'target_department' => 'registrar',
                'event_code' => 'student_case_summary',
                'label' => 'Sync to Registrar',
            ],
            [
                'route_key' => 'guidance_to_prefect_behavior_referral',
                'target_department' => 'prefect',
                'event_code' => 'behavior_referral',
                'label' => 'Refer to Prefect',
            ],
            [
                'route_key' => 'guidance_to_pmed_wellness_summary',
                'target_department' => 'pmed',
                'event_code' => 'wellness_summary',
                'label' => 'Share to PMED',
            ],
        ],
        'crisis' => [
            [
                'route_key' => 'guidance_to_prefect_discipline_report',
                'target_department' => 'prefect',
                'event_code' => 'discipline_report',
                'label' => 'Sync to Prefect',
            ],
            [
                'route_key' => 'guidance_to_pmed_incident_monitoring_update',
                'target_department' => 'pmed',
                'event_code' => 'incident_monitoring_update',
                'label' => 'Sync to PMED',
            ],
        ],
    ];
}

function guidance_integration_status_options(): array
{
    return ['Queued', 'Received', 'Acknowledged', 'Sent', 'Failed'];
}

function guidance_integration_numeric_sql_value($value): string
{
    $value = trim((string) $value);
    return preg_match('/^\d+$/', $value) ? $value : 'NULL';
}

function guidance_integration_quote(?string $value): string
{
    if ($value === null || $value === '') {
        return 'NULL';
    }

    return "'" . str_replace("'", "''", $value) . "'";
}

function guidance_integration_normalize_department(string $value): string
{
    return strtolower(trim($value));
}

function guidance_integration_department_label(string $key): string
{
    $departments = guidance_integration_departments();
    return $departments[$key]['label'] ?? strtoupper($key);
}

function guidance_integration_flow_label(string $departmentKey, string $eventCode, string $direction): string
{
    $departments = guidance_integration_departments();
    $flows = $departments[$departmentKey][$direction . '_flows'] ?? [];
    return $flows[$eventCode] ?? ucwords(str_replace('_', ' ', $eventCode));
}

function guidance_integration_resolve_flow_type(string $sourceDepartment, string $targetDepartment, string $eventCode): string
{
    $sourceDepartment = guidance_integration_normalize_department($sourceDepartment);
    $targetDepartment = guidance_integration_normalize_department($targetDepartment);
    $departments = guidance_integration_departments();

    if ($sourceDepartment === 'guidance' && isset($departments[$targetDepartment])) {
        return guidance_integration_flow_label($targetDepartment, $eventCode, 'outbound');
    }

    if ($targetDepartment === 'guidance' && isset($departments[$sourceDepartment])) {
        return guidance_integration_flow_label($sourceDepartment, $eventCode, 'inbound');
    }

    return ucwords(str_replace('_', ' ', $eventCode));
}

function guidance_integration_ensure_schema($conn): void
{
    $queries = [
        "ALTER TABLE students ADD COLUMN IF NOT EXISTS section_name VARCHAR(100) NULL",
        "ALTER TABLE students ADD COLUMN IF NOT EXISTS enrollment_status VARCHAR(50) NULL DEFAULT 'Active'",
        "ALTER TABLE students ADD COLUMN IF NOT EXISTS registrar_status VARCHAR(50) NULL DEFAULT 'Synced'",
        "ALTER TABLE students ADD COLUMN IF NOT EXISTS external_profile JSONB NULL",
        "ALTER TABLE students ADD COLUMN IF NOT EXISTS synced_at TIMESTAMPTZ NULL",
        "ALTER TABLE guidance ADD COLUMN IF NOT EXISTS student_id VARCHAR(50) NULL",
        "ALTER TABLE guidance ADD COLUMN IF NOT EXISTS case_reference VARCHAR(80) NULL",
        "ALTER TABLE guidance ADD COLUMN IF NOT EXISTS category VARCHAR(50) NULL DEFAULT 'general'",
        "ALTER TABLE guidance ADD COLUMN IF NOT EXISTS priority_level VARCHAR(20) NULL DEFAULT 'medium'",
        "ALTER TABLE guidance ADD COLUMN IF NOT EXISTS referral_status VARCHAR(30) NULL DEFAULT 'internal_review'",
        "ALTER TABLE guidance ADD COLUMN IF NOT EXISTS shared_with JSONB NULL",
        "ALTER TABLE guidance ADD COLUMN IF NOT EXISTS synced_at TIMESTAMPTZ NULL",
        "ALTER TABLE counseling ADD COLUMN IF NOT EXISTS case_reference VARCHAR(80) NULL",
        "ALTER TABLE counseling ADD COLUMN IF NOT EXISTS risk_level VARCHAR(20) NULL DEFAULT 'medium'",
        "ALTER TABLE counseling ADD COLUMN IF NOT EXISTS referral_status VARCHAR(30) NULL DEFAULT 'internal_review'",
        "ALTER TABLE counseling ADD COLUMN IF NOT EXISTS source_department VARCHAR(50) NULL DEFAULT 'guidance'",
        "ALTER TABLE counseling ADD COLUMN IF NOT EXISTS shared_with JSONB NULL",
        "ALTER TABLE counseling ADD COLUMN IF NOT EXISTS synced_at TIMESTAMPTZ NULL",
        "ALTER TABLE crisis ADD COLUMN IF NOT EXISTS student_id VARCHAR(50) NULL",
        "ALTER TABLE crisis ADD COLUMN IF NOT EXISTS case_reference VARCHAR(80) NULL",
        "ALTER TABLE crisis ADD COLUMN IF NOT EXISTS severity_level VARCHAR(20) NULL DEFAULT 'high'",
        "ALTER TABLE crisis ADD COLUMN IF NOT EXISTS referral_status VARCHAR(30) NULL DEFAULT 'internal_review'",
        "ALTER TABLE crisis ADD COLUMN IF NOT EXISTS shared_with JSONB NULL",
        "ALTER TABLE crisis ADD COLUMN IF NOT EXISTS synced_at TIMESTAMPTZ NULL",
        "CREATE TABLE IF NOT EXISTS integration_flows (
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
        )",
        "ALTER TABLE integration_flows ADD COLUMN IF NOT EXISTS route_key VARCHAR(120) NULL",
        "ALTER TABLE integration_flows ADD COLUMN IF NOT EXISTS event_code VARCHAR(120) NULL",
        "ALTER TABLE integration_flows ADD COLUMN IF NOT EXISTS correlation_id VARCHAR(120) NULL",
        "ALTER TABLE integration_flows ADD COLUMN IF NOT EXISTS source_record_table VARCHAR(80) NULL",
        "ALTER TABLE integration_flows ADD COLUMN IF NOT EXISTS source_record_id BIGINT NULL",
        "ALTER TABLE integration_flows ADD COLUMN IF NOT EXISTS payload_json JSONB NULL",
        "ALTER TABLE integration_flows ADD COLUMN IF NOT EXISTS response_payload JSONB NULL",
        "ALTER TABLE integration_flows ADD COLUMN IF NOT EXISTS last_error TEXT NULL",
        "ALTER TABLE integration_flows ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()",
        "CREATE INDEX IF NOT EXISTS idx_guidance_integration_direction_status ON integration_flows(direction, status)",
        "CREATE INDEX IF NOT EXISTS idx_guidance_integration_created_at ON integration_flows(created_at DESC)",
        "CREATE INDEX IF NOT EXISTS idx_guidance_integration_route_key ON integration_flows(route_key)",
        "CREATE INDEX IF NOT EXISTS idx_guidance_integration_event_code ON integration_flows(event_code)",
        "CREATE INDEX IF NOT EXISTS idx_guidance_integration_correlation_id ON integration_flows(correlation_id)",
    ];

    foreach ($queries as $query) {
        $conn->query($query);
    }
}

function guidance_integration_fetch_rows($conn, string $sql): ?array
{
    $rows = [];
    $result = $conn->query($sql);

    if (!$result || !method_exists($result, 'fetch_assoc')) {
        return null;
    }

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function guidance_integration_fetch_dataset($conn, array $sources): array
{
    foreach ($sources as $source) {
        $rows = guidance_integration_fetch_rows($conn, (string) ($source['sql'] ?? ''));
        if ($rows !== null) {
            return [
                'rows' => $rows,
                'source_label' => (string) ($source['label'] ?? 'Shared Supabase'),
                'is_available' => true,
            ];
        }
    }

    return [
        'rows' => [],
        'source_label' => 'Unavailable',
        'is_available' => false,
    ];
}

function guidance_fetch_registrar_directory($conn, int $limit = 40): array
{
    $limit = max(1, min(200, $limit));

    $bridgeSql = "SELECT
        rs.id AS source_record_id,
        rs.student_no AS student_id,
        TRIM(CONCAT_WS(' ', rs.first_name, rs.last_name)) AS student_name,
        rs.program AS course,
        rs.year_level,
        COALESCE(MAX(re.status), rs.status, 'Active') AS enrollment_status,
        COALESCE(STRING_AGG(DISTINCT rc.class_code, ', ' ORDER BY rc.class_code), '') AS subject_load,
        COUNT(DISTINCT rc.id) AS subject_count,
        MAX(rs.created_at) AS source_updated_at
    FROM public.registrar_students rs
    LEFT JOIN public.registrar_enrollments re ON re.student_id = rs.id
    LEFT JOIN public.registrar_classes rc ON rc.id = re.class_id
    GROUP BY rs.id, rs.student_no, rs.first_name, rs.last_name, rs.program, rs.year_level, rs.status
    ORDER BY TRIM(CONCAT_WS(' ', rs.first_name, rs.last_name)) ASC
    LIMIT {$limit}";

    $schemaSql = "SELECT
        rs.id AS source_record_id,
        rs.student_no AS student_id,
        TRIM(CONCAT_WS(' ', rs.first_name, rs.last_name)) AS student_name,
        rs.program AS course,
        rs.year_level,
        COALESCE(MAX(re.status), rs.status, 'Active') AS enrollment_status,
        COALESCE(STRING_AGG(DISTINCT rc.class_code, ', ' ORDER BY rc.class_code), '') AS subject_load,
        COUNT(DISTINCT rc.id) AS subject_count,
        MAX(rs.created_at) AS source_updated_at
    FROM registrar.students rs
    LEFT JOIN registrar.enrollments re ON re.student_id = rs.id
    LEFT JOIN registrar.classes rc ON rc.id = re.class_id
    GROUP BY rs.id, rs.student_no, rs.first_name, rs.last_name, rs.program, rs.year_level, rs.status
    ORDER BY TRIM(CONCAT_WS(' ', rs.first_name, rs.last_name)) ASC
    LIMIT {$limit}";

    return guidance_integration_fetch_dataset($conn, [
        ['label' => 'public.registrar_* bridge views', 'sql' => $bridgeSql],
        ['label' => 'registrar schema tables', 'sql' => $schemaSql],
    ]);
}

function guidance_fetch_prefect_incidents($conn, int $limit = 20): array
{
    $limit = max(1, min(200, $limit));

    $bridgeSql = "SELECT
        ir.id AS source_record_id,
        COALESCE(pr.student_id, '') AS student_id,
        NULLIF(TRIM(CONCAT_WS(' ', pr.first_name, pr.last_name)), '') AS student_name,
        ir.title,
        ir.description,
        ir.severity::text AS severity,
        ir.location,
        ir.incident_date,
        CASE WHEN ir.is_resolved THEN 'Resolved' ELSE 'Open' END AS incident_status,
        COALESCE(ir.updated_at, ir.created_at) AS source_updated_at
    FROM public.prefect_incident_reports ir
    LEFT JOIN public.prefect_profiles pr ON pr.id = ir.reported_by
    ORDER BY ir.incident_date DESC, ir.created_at DESC
    LIMIT {$limit}";

    $schemaSql = "SELECT
        ir.id AS source_record_id,
        COALESCE(pr.student_id, '') AS student_id,
        NULLIF(TRIM(CONCAT_WS(' ', pr.first_name, pr.last_name)), '') AS student_name,
        ir.title,
        ir.description,
        ir.severity::text AS severity,
        ir.location,
        ir.incident_date,
        CASE WHEN ir.is_resolved THEN 'Resolved' ELSE 'Open' END AS incident_status,
        COALESCE(ir.updated_at, ir.created_at) AS source_updated_at
    FROM prefect.incident_reports ir
    LEFT JOIN prefect.profiles pr ON pr.id = ir.reported_by
    ORDER BY ir.incident_date DESC, ir.created_at DESC
    LIMIT {$limit}";

    return guidance_integration_fetch_dataset($conn, [
        ['label' => 'public.prefect_* bridge views', 'sql' => $bridgeSql],
        ['label' => 'prefect schema tables', 'sql' => $schemaSql],
    ]);
}

function guidance_fetch_pmed_monitoring_sessions($conn, int $limit = 20): array
{
    $limit = max(1, min(200, $limit));

    $bridgeSql = "SELECT
        s.id AS source_record_id,
        s.case_reference,
        COALESCE(pm.patient_code, s.patient_id) AS student_id,
        s.patient_name AS student_name,
        s.counselor,
        s.session_type,
        s.status AS session_status,
        COALESCE(s.risk_level, pm.risk_level, 'low') AS risk_level,
        COALESCE(s.outcome_result, s.treatment_plan, s.session_goals, '') AS summary,
        COALESCE(s.next_follow_up_at, s.appointment_at) AS follow_up_at,
        COALESCE(pm.latest_status, s.status, 'active') AS monitoring_status,
        COALESCE(pm.last_seen_at, s.updated_at, s.created_at) AS source_updated_at
    FROM public.pmed_mental_health_sessions s
    LEFT JOIN public.pmed_patient_master pm
        ON pm.patient_code = s.patient_id OR pm.patient_name = s.patient_name
    ORDER BY COALESCE(s.next_follow_up_at, s.appointment_at) DESC, s.created_at DESC
    LIMIT {$limit}";

    $schemaSql = "SELECT
        s.id AS source_record_id,
        s.case_reference,
        COALESCE(pm.patient_code, s.patient_id) AS student_id,
        s.patient_name AS student_name,
        s.counselor,
        s.session_type,
        s.status AS session_status,
        COALESCE(s.risk_level, pm.risk_level, 'low') AS risk_level,
        COALESCE(s.outcome_result, s.treatment_plan, s.session_goals, '') AS summary,
        COALESCE(s.next_follow_up_at, s.appointment_at) AS follow_up_at,
        COALESCE(pm.latest_status, s.status, 'active') AS monitoring_status,
        COALESCE(pm.last_seen_at, s.updated_at, s.created_at) AS source_updated_at
    FROM pmed.mental_health_sessions s
    LEFT JOIN pmed.patient_master pm
        ON pm.patient_code = s.patient_id OR pm.patient_name = s.patient_name
    ORDER BY COALESCE(s.next_follow_up_at, s.appointment_at) DESC, s.created_at DESC
    LIMIT {$limit}";

    return guidance_integration_fetch_dataset($conn, [
        ['label' => 'public.pmed_* bridge views', 'sql' => $bridgeSql],
        ['label' => 'pmed schema views', 'sql' => $schemaSql],
    ]);
}

function guidance_integration_dispatch_flow(
    $conn,
    string $sourceDepartment,
    string $targetDepartment,
    string $eventCode,
    array $options = []
): bool {
    $sourceDepartment = guidance_integration_normalize_department($sourceDepartment);
    $targetDepartment = guidance_integration_normalize_department($targetDepartment);
    $flowType = $options['flow_type'] ?? guidance_integration_resolve_flow_type($sourceDepartment, $targetDepartment, $eventCode);
    $routeKey = $options['route_key'] ?? ($sourceDepartment . '_to_' . $targetDepartment . '_' . $eventCode);
    $correlationId = $options['correlation_id'] ?? uniqid($sourceDepartment . '_', true);
    $referenceTable = $options['reference_table'] ?? null;
    $referenceId = isset($options['reference_id']) && $options['reference_id'] !== '' ? (string) $options['reference_id'] : null;
    $referenceIdSql = $referenceId !== null ? guidance_integration_numeric_sql_value($referenceId) : 'NULL';
    $studentId = $options['student_id'] ?? null;
    $studentName = $options['student_name'] ?? null;
    $payloadSummary = $options['payload_summary'] ?? null;
    $payloadJson = $options['payload_json'] ?? [];
    $payloadJsonSql = guidance_integration_quote(json_encode($payloadJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $duplicateCheck = $conn->query(
        "SELECT * FROM integration_flows WHERE correlation_id = " . guidance_integration_quote($correlationId) . " ORDER BY id DESC LIMIT 1"
    );

    if ($duplicateCheck && method_exists($duplicateCheck, 'fetch_assoc') && $duplicateCheck->fetch_assoc()) {
        return true;
    }

    $outboundSql = "INSERT INTO integration_flows (
        direction, source_department, target_department, flow_type, reference_table, reference_id,
        student_id, student_name, payload_summary, status, sent_at, route_key, event_code, correlation_id,
        source_record_table, source_record_id, payload_json, updated_at
    ) VALUES (
        'OUTBOUND',
        " . guidance_integration_quote($sourceDepartment) . ",
        " . guidance_integration_quote($targetDepartment) . ",
        " . guidance_integration_quote($flowType) . ",
        " . guidance_integration_quote($referenceTable) . ",
        {$referenceIdSql},
        " . guidance_integration_quote($studentId) . ",
        " . guidance_integration_quote($studentName) . ",
        " . guidance_integration_quote($payloadSummary) . ",
        'Sent',
        NOW(),
        " . guidance_integration_quote($routeKey) . ",
        " . guidance_integration_quote($eventCode) . ",
        " . guidance_integration_quote($correlationId) . ",
        " . guidance_integration_quote($referenceTable) . ",
        {$referenceIdSql},
        {$payloadJsonSql}::jsonb,
        NOW()
    )";

    $inboundSql = "INSERT INTO integration_flows (
        direction, source_department, target_department, flow_type, reference_table, reference_id,
        student_id, student_name, payload_summary, status, received_at, route_key, event_code, correlation_id,
        source_record_table, source_record_id, payload_json, updated_at
    ) VALUES (
        'INBOUND',
        " . guidance_integration_quote($sourceDepartment) . ",
        " . guidance_integration_quote($targetDepartment) . ",
        " . guidance_integration_quote($flowType) . ",
        " . guidance_integration_quote($referenceTable) . ",
        {$referenceIdSql},
        " . guidance_integration_quote($studentId) . ",
        " . guidance_integration_quote($studentName) . ",
        " . guidance_integration_quote($payloadSummary) . ",
        'Received',
        NOW(),
        " . guidance_integration_quote($routeKey) . ",
        " . guidance_integration_quote($eventCode) . ",
        " . guidance_integration_quote($correlationId) . ",
        " . guidance_integration_quote($referenceTable) . ",
        {$referenceIdSql},
        {$payloadJsonSql}::jsonb,
        NOW()
    )";

    return (bool) $conn->query($outboundSql) && (bool) $conn->query($inboundSql);
}

function guidance_receive_shared_department_event(
    $conn,
    string $sourceDepartment,
    string $eventCode,
    array $options = []
): bool {
    $correlationId = $options['correlation_id'] ?? uniqid(guidance_integration_normalize_department($sourceDepartment) . '_', true);
    $options['correlation_id'] = $correlationId;

    $dispatched = guidance_integration_dispatch_flow(
        $conn,
        $sourceDepartment,
        'guidance',
        $eventCode,
        $options
    );

    if (!$dispatched) {
        return false;
    }

    $event = guidance_get_flow_event($conn, null, $correlationId);
    if (!$event || ($event['direction'] ?? '') !== 'INBOUND') {
        return false;
    }

    return guidance_apply_inbound_event($conn, (int) $event['id']);
}

function guidance_integration_queue_outbound(
    $conn,
    string $targetDepartment,
    string $eventCode,
    array $options = []
): bool {
    $targetDepartment = guidance_integration_normalize_department($targetDepartment);
    $sourceDepartment = guidance_integration_normalize_department((string) ($options['source_department'] ?? 'guidance'));
    return guidance_integration_dispatch_flow($conn, $sourceDepartment, $targetDepartment, $eventCode, $options);
}

function guidance_fetch_students($conn): array
{
    $rows = [];
    $result = $conn->query("SELECT * FROM students ORDER BY name ASC, student_id ASC");

    if ($result && method_exists($result, 'fetch_assoc')) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function guidance_find_student_by_student_id($conn, string $studentId): ?array
{
    $studentId = trim($studentId);
    if ($studentId === '') {
        return null;
    }

    $result = $conn->query(
        "SELECT * FROM students WHERE student_id = " . guidance_integration_quote($studentId) . " ORDER BY id DESC LIMIT 1"
    );

    if ($result && method_exists($result, 'fetch_assoc')) {
        $row = $result->fetch_assoc();
        return $row ?: null;
    }

    return null;
}

function guidance_receive_student_profile_from_registrar(
    $conn,
    array $studentProfile,
    array $options = []
): bool {
    $studentId = trim((string) ($studentProfile['student_id'] ?? ''));
    $name = trim((string) ($studentProfile['name'] ?? ''));

    if ($studentId === '' || $name === '') {
        return false;
    }

    $course = trim((string) ($studentProfile['course'] ?? ''));
    $yearLevel = trim((string) ($studentProfile['year_level'] ?? ''));
    $sectionName = trim((string) ($studentProfile['section_name'] ?? ''));
    $enrollmentStatus = trim((string) ($studentProfile['enrollment_status'] ?? 'Enrolled'));
    $subjectLoad = $studentProfile['subject_load'] ?? [];
    $existing = guidance_find_student_by_student_id($conn, $studentId);

    $externalProfile = json_encode([
        'source_department' => 'registrar',
        'student_id' => $studentId,
        'course' => $course,
        'year_level' => $yearLevel,
        'section_name' => $sectionName,
        'enrollment_status' => $enrollmentStatus,
        'subject_load' => $subjectLoad,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($existing) {
        $sql = "UPDATE students SET
            name = " . guidance_integration_quote($name) . ",
            course = " . guidance_integration_quote($course !== '' ? $course : null) . ",
            year_level = " . guidance_integration_quote($yearLevel !== '' ? $yearLevel : null) . ",
            section_name = " . guidance_integration_quote($sectionName !== '' ? $sectionName : null) . ",
            enrollment_status = " . guidance_integration_quote($enrollmentStatus) . ",
            registrar_status = 'Synced',
            external_profile = " . guidance_integration_quote($externalProfile) . "::jsonb,
            synced_at = NOW()
        WHERE id = " . (int) $existing['id'];
    } else {
        $sql = "INSERT INTO students (
            student_id, name, course, year_level, section_name, enrollment_status, registrar_status, external_profile, synced_at
        ) VALUES (
            " . guidance_integration_quote($studentId) . ",
            " . guidance_integration_quote($name) . ",
            " . guidance_integration_quote($course !== '' ? $course : null) . ",
            " . guidance_integration_quote($yearLevel !== '' ? $yearLevel : null) . ",
            " . guidance_integration_quote($sectionName !== '' ? $sectionName : null) . ",
            " . guidance_integration_quote($enrollmentStatus) . ",
            'Synced',
            " . guidance_integration_quote($externalProfile) . "::jsonb,
            NOW()
        )";
    }

    if (!$conn->query($sql)) {
        return false;
    }

    $recordFlow = array_key_exists('record_flow', $options) ? (bool) $options['record_flow'] : true;
    $correlationId = $options['correlation_id'] ?? uniqid('registrar_', true);
    $routeKey = $options['route_key'] ?? 'registrar_to_guidance_student_profile_sync';
    $eventCode = $options['event_code'] ?? 'student_profile_sync';
    $flowType = $options['flow_type'] ?? 'Student Profile Sync';
    $payloadJson = json_encode([
        'student_id' => $studentId,
        'student_name' => $name,
        'course' => $course,
        'year_level' => $yearLevel,
        'section_name' => $sectionName,
        'enrollment_status' => $enrollmentStatus,
        'subject_load' => $subjectLoad,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!$recordFlow) {
        return true;
    }

    $duplicateEvent = guidance_get_flow_event($conn, null, $correlationId);
    if ($duplicateEvent) {
        return true;
    }

    $flowSql = "INSERT INTO integration_flows (
        direction, source_department, target_department, flow_type, student_id, student_name,
        payload_summary, status, received_at, route_key, event_code, correlation_id, payload_json, updated_at
    ) VALUES (
        'INBOUND',
        'registrar',
        'guidance',
        " . guidance_integration_quote($flowType) . ",
        " . guidance_integration_quote($studentId) . ",
        " . guidance_integration_quote($name) . ",
        " . guidance_integration_quote(trim($course . ' / ' . $yearLevel . ' / ' . $sectionName)) . ",
        'Received',
        NOW(),
        " . guidance_integration_quote($routeKey) . ",
        " . guidance_integration_quote($eventCode) . ",
        " . guidance_integration_quote($correlationId) . ",
        " . guidance_integration_quote($payloadJson) . "::jsonb,
        NOW()
    )";

    return (bool) $conn->query($flowSql);
}

function guidance_get_flow_event($conn, ?int $eventId = null, ?string $correlationId = null): ?array
{
    if ($eventId === null && ($correlationId === null || trim($correlationId) === '')) {
        return null;
    }

    $where = $eventId !== null
        ? 'id = ' . (int) $eventId
        : 'correlation_id = ' . guidance_integration_quote(trim((string) $correlationId));

    $result = $conn->query("SELECT * FROM integration_flows WHERE {$where} ORDER BY id DESC LIMIT 1");

    if ($result && method_exists($result, 'fetch_assoc')) {
        $row = $result->fetch_assoc();
        return $row ?: null;
    }

    return null;
}

function guidance_create_guidance_record_from_payload($conn, string $category, array $payload): bool
{
    $studentId = trim((string) ($payload['student_id'] ?? ''));
    $studentName = trim((string) ($payload['student_name'] ?? ($payload['name'] ?? '')));
    $concern = trim((string) ($payload['summary'] ?? $payload['incident'] ?? $payload['issue'] ?? 'Inbound department record'));
    $actionTaken = trim((string) ($payload['recommended_action'] ?? $payload['action_taken'] ?? 'Inbound record received from connected department.'));
    $priority = trim((string) ($payload['priority_level'] ?? $payload['severity_level'] ?? 'medium'));
    $caseReference = trim((string) ($payload['case_reference'] ?? ''));

    if ($studentId === '' && $studentName === '') {
        return false;
    }

    if ($caseReference === '') {
        $caseReference = 'GDN-' . date('Y') . '-' . strtoupper(substr(uniqid(), -4));
    } else {
        $existing = $conn->query(
            "SELECT * FROM guidance WHERE case_reference = " . guidance_integration_quote($caseReference) . " ORDER BY id DESC LIMIT 1"
        );
        if ($existing && method_exists($existing, 'fetch_assoc') && $existing->fetch_assoc()) {
            return true;
        }
    }

    return (bool) $conn->query("INSERT INTO guidance(
        student_name, student_id, concern, action_taken, date_recorded, status, case_reference, category, priority_level, referral_status, synced_at
    ) VALUES(
        " . guidance_integration_quote($studentName !== '' ? $studentName : 'Unknown Student') . ",
        " . guidance_integration_quote($studentId !== '' ? $studentId : null) . ",
        " . guidance_integration_quote($concern) . ",
        " . guidance_integration_quote($actionTaken) . ",
        CURRENT_DATE,
        'Pending',
        " . guidance_integration_quote($caseReference) . ",
        " . guidance_integration_quote($category) . ",
        " . guidance_integration_quote($priority) . ",
        'received',
        NOW()
    )");
}

function guidance_apply_inbound_event($conn, int $eventId): bool
{
    $event = guidance_get_flow_event($conn, $eventId, null);
    if (!$event || ($event['direction'] ?? '') !== 'INBOUND' || guidance_integration_normalize_department((string) ($event['target_department'] ?? '')) !== 'guidance') {
        return false;
    }

    $payload = [];
    if (!empty($event['payload_json'])) {
        $decoded = json_decode((string) $event['payload_json'], true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $sourceDepartment = guidance_integration_normalize_department((string) ($event['source_department'] ?? ''));
    $eventCode = trim((string) ($event['event_code'] ?? ''));
    $applied = true;

    if ($sourceDepartment === 'registrar' && $eventCode === 'student_profile_sync') {
        $applied = guidance_receive_student_profile_from_registrar($conn, $payload, [
            'correlation_id' => (string) ($event['correlation_id'] ?? ''),
            'route_key' => (string) ($event['route_key'] ?? 'registrar_to_guidance_student_profile_sync'),
            'event_code' => $eventCode,
            'flow_type' => 'Student Profile Sync',
            'record_flow' => false,
        ]);
    } elseif ($sourceDepartment === 'prefect' && $eventCode === 'offense_report') {
        $applied = guidance_create_guidance_record_from_payload($conn, 'behavior', $payload);
    } elseif ($sourceDepartment === 'pmed' && $eventCode === 'monitoring_summary') {
        $applied = guidance_create_guidance_record_from_payload($conn, 'wellness', $payload);
    }

    if ($applied) {
        $correlationId = (string) ($event['correlation_id'] ?? '');
        $responsePayload = guidance_integration_quote(json_encode([
            'applied_by' => 'guidance',
            'applied_at' => date(DATE_ATOM),
            'event_code' => $eventCode,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $conn->query("UPDATE integration_flows SET
            status='Acknowledged',
            acknowledged_at=NOW(),
            response_payload={$responsePayload}::jsonb,
            last_error=NULL,
            updated_at=NOW()
        WHERE correlation_id=" . guidance_integration_quote($correlationId) . " AND direction='INBOUND'");

        $conn->query("UPDATE integration_flows SET
            status='Acknowledged',
            acknowledged_at=NOW(),
            response_payload={$responsePayload}::jsonb,
            last_error=NULL,
            updated_at=NOW()
        WHERE correlation_id=" . guidance_integration_quote($correlationId) . " AND direction='OUTBOUND'");
    }

    return $applied;
}

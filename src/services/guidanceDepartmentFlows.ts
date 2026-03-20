import { dispatchDepartmentFlow, getFlowEventStatus, type FlowEventStatus } from './departmentIntegration';

type TrackedGuidanceDispatch<T> = FlowEventStatus & {
  payload?: T;
};

async function trackGuidanceDispatch<T>(
  targetDepartment: 'clinic' | 'pmed',
  eventCode: string,
  payload: Record<string, unknown>,
  sourceRecordId: string | undefined,
  attachment: T,
  fallbackMessage: string
): Promise<TrackedGuidanceDispatch<T>> {
  const result = await dispatchDepartmentFlow('guidance', targetDepartment, eventCode, payload, sourceRecordId);

  if (result.ok && result.correlation_id) {
    const status = await getFlowEventStatus(undefined, result.correlation_id);
    return {
      ...status,
      payload: attachment
    };
  }

  return {
    ok: false,
    last_error: result.message || fallbackMessage,
    payload: attachment
  };
}

export type GuidanceHealthConcern = {
  student_id: string;
  student_name: string;
  concern_summary: string;
  case_reference?: string;
  priority?: 'low' | 'medium' | 'high' | 'urgent';
};

export async function dispatchHealthConcernToClinic(
  concern: GuidanceHealthConcern,
  sourceRecordId?: string
): Promise<TrackedGuidanceDispatch<GuidanceHealthConcern>> {
  return await trackGuidanceDispatch(
    'clinic',
    'health_concerns',
    {
      student_id: concern.student_id,
      student_name: concern.student_name,
      concern_summary: concern.concern_summary,
      case_reference: concern.case_reference,
      priority: concern.priority || 'medium'
    },
    sourceRecordId,
    concern,
    'Failed to dispatch health concern to Clinic.'
  );
}

export type GuidanceCounselingReport = {
  student_id: string;
  student_name: string;
  report_summary: string;
  case_reference?: string;
  risk_level?: 'low' | 'medium' | 'high' | 'critical';
};

export async function dispatchCounselingReportToPmed(
  report: GuidanceCounselingReport,
  sourceRecordId?: string
): Promise<TrackedGuidanceDispatch<GuidanceCounselingReport>> {
  return await trackGuidanceDispatch(
    'pmed',
    'counseling_reports',
    {
      student_id: report.student_id,
      student_name: report.student_name,
      report_summary: report.report_summary,
      case_reference: report.case_reference,
      risk_level: report.risk_level || 'medium'
    },
    sourceRecordId,
    report,
    'Failed to dispatch counseling report to PMED.'
  );
}

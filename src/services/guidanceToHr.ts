import { dispatchDepartmentFlow, getFlowEventStatus, type FlowEventStatus } from './departmentIntegration';

export type StaffWelfareReferral = {
  employee_id: string;
  employee_name: string;
  referral_reason: string;
  case_reference?: string;
  priority?: 'low' | 'medium' | 'high' | 'urgent';
  recommendation?: string;
  referral_date?: string;
  referred_by?: string;
  notes?: string;
};

export type StaffWelfareReferralResult = FlowEventStatus & {
  referral?: StaffWelfareReferral;
};

export async function dispatchStaffWelfareReferralToHR(
  referral: StaffWelfareReferral,
  sourceRecordId?: string
): Promise<StaffWelfareReferralResult> {
  const payload: Record<string, unknown> = {
    employee_id: referral.employee_id,
    employee_name: referral.employee_name,
    referral_reason: referral.referral_reason,
    case_reference: referral.case_reference,
    priority: referral.priority || 'medium',
    recommendation: referral.recommendation,
    referral_date: referral.referral_date || new Date().toISOString(),
    referred_by: referral.referred_by,
    notes: referral.notes
  };

  const result = await dispatchDepartmentFlow(
    'guidance',
    'hr',
    'staff_welfare_referrals',
    payload,
    sourceRecordId
  );

  if (result.ok && result.correlation_id) {
    const status = await getFlowEventStatus(undefined, result.correlation_id);
    return {
      ...status,
      referral
    };
  }

  return {
    ok: false,
    last_error: result.message || 'Failed to dispatch staff welfare referral'
  } as StaffWelfareReferralResult;
}

export async function referStaffWelfareConcern(
  employeeId: string,
  employeeName: string,
  reason: string,
  priority: StaffWelfareReferral['priority'] = 'medium',
  recommendation?: string
): Promise<StaffWelfareReferralResult> {
  return dispatchStaffWelfareReferralToHR({
    employee_id: employeeId,
    employee_name: employeeName,
    referral_reason: reason,
    priority,
    recommendation
  });
}

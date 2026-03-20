<?php
include "../config/db.php";
include "_shared.php";

$result = $conn->query("SELECT * FROM survey ORDER BY date_submitted DESC, id DESC");
$surveyTotal = $result ? $result->num_rows : 0;
$pendingResult = $conn->query("SELECT * FROM survey WHERE status='Pending'");
$pendingTotal = $pendingResult ? $pendingResult->num_rows : 0;
$reviewedResult = $conn->query("SELECT * FROM survey WHERE status='Reviewed'");
$reviewedTotal = $reviewedResult ? $reviewedResult->num_rows : 0;

guidance_render_shell_start(
    'Survey & Feedback',
    'Survey and Feedback Review',
    'Review student sentiment, close pending feedback items, and route relevant wellness insights to PMED.',
    [
        ['label' => 'Feedback Entries', 'value' => $surveyTotal, 'note' => 'Total survey and feedback submissions available for review.'],
        ['label' => 'Pending Review', 'value' => $pendingTotal, 'note' => 'Feedback items still waiting for Guidance action.'],
        ['label' => 'Reviewed', 'value' => $reviewedTotal, 'note' => 'Entries already marked complete by Guidance staff.'],
        ['label' => 'Integration Route', 'value' => 'PMED', 'note' => 'Wellness-relevant feedback may be routed to PMED through the hub.'],
    ],
    [
        ['label' => 'Review Pending Feedback', 'href' => '#survey-table', 'class' => 'btn-primary'],
        ['label' => 'Open Integration Hub', 'href' => 'integration.php', 'class' => 'btn-secondary'],
    ],
    ['Feedback Intake', 'Guidance Review', 'PMED Endorsement', 'Archive or Delete']
);
?>

<div class="split-layout">
    <section class="table-panel" id="survey-table">
        <div class="panel-heading">
            <div>
                <h2>Survey Review Queue</h2>
                <p>Review student feedback, mark it as handled, and send relevant concerns to PMED.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <tr>
                    <th>Date Submitted</th>
                    <th>Student Name</th>
                    <th>Feedback</th>
                    <th>Rating</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>

                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo guidance_escape($row['date_submitted']); ?></td>
                        <td><?php echo guidance_escape($row['student_name']); ?></td>
                        <td><?php echo guidance_escape($row['feedback']); ?></td>
                        <td><?php echo guidance_escape($row['rating']); ?></td>
                        <td><span class="status-pill modern <?php echo strtolower(guidance_escape($row['status'])); ?>"><?php echo guidance_escape($row['status']); ?></span></td>
                        <td>
                            <div class="table-actions">
                                <?php if ($row['status'] == 'Pending'): ?>
                                    <a class="table-link" href="survey_review.php?id=<?php echo $row['id']; ?>">Mark as Reviewed</a>
                                <?php else: ?>
                                    <span class="table-link success">Reviewed by <?php echo guidance_escape($row['reviewed_by'] ?? 'Admin'); ?></span>
                                <?php endif; ?>
                                <a class="table-link success" href="integration.php?sync=1&target=PMED&flow_type=Counseling Report&table=survey&id=<?php echo $row['id']; ?>&student_id=<?php echo urlencode($row['student_id']); ?>&student_name=<?php echo urlencode($row['student_name']); ?>&summary=<?php echo urlencode($row['feedback']); ?>">Sync to PMED</a>
                                <a class="table-link danger" href="survey_delete.php?id=<?php echo $row['id']; ?>" onclick="return confirm('Are you sure you want to delete this survey?');">Delete</a>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </table>
        </div>
    </section>

    <aside class="insight-panel soft">
        <div class="panel-heading">
            <div>
                <h3>Review Logic</h3>
                <p>Survey feedback helps Guidance identify student concerns that may require counseling or wellness escalation.</p>
            </div>
        </div>
        <div class="mini-list">
            <article class="mini-list-item">
                <div class="mini-list-title">Pending feedback</div>
                <div class="mini-list-note">Items in this state still need interpretation and office acknowledgment.</div>
            </article>
            <article class="mini-list-item">
                <div class="mini-list-title">Reviewed feedback</div>
                <div class="mini-list-note">Entries already checked can remain on file as reference for trend analysis.</div>
            </article>
            <article class="mini-list-item">
                <div class="mini-list-title">PMED sync</div>
                <div class="mini-list-note">When student wellness patterns emerge, route the summary to PMED through Integration Hub.</div>
            </article>
        </div>
    </aside>
</div>

<?php guidance_render_shell_end(); ?>

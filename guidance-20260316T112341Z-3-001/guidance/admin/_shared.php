<?php

function guidance_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function guidance_nav_items(): array
{
    return [
        ['label' => 'Dashboard', 'href' => 'dashboard.php'],
        ['label' => 'Student Info', 'href' => 'students.php'],
        ['label' => 'Connected Data', 'href' => 'connected_data.php'],
        ['label' => 'Academic Counseling', 'href' => 'counseling.php'],
        ['label' => 'Referrals & Monitoring', 'href' => 'guidance.php'],
        ['label' => 'Incident Desk', 'href' => 'crisis.php'],
        ['label' => 'Survey & Feedback', 'href' => 'survey.php'],
        ['label' => 'Integration Hub', 'href' => 'integration.php'],
        ['label' => 'Logout', 'href' => '../logout.php'],
    ];
}

function guidance_render_shell_start(
    string $activeNav,
    string $pageTitle,
    string $pageDescription,
    array $stats = [],
    array $actions = [],
    array $flow = []
): void {
    $brandLogo = '../../../../Registrar/assets/img/logo.png';
    ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">

    <div class="sidebar guidance-sidebar">
        <div class="sidebar-brand">
            <img src="<?php echo guidance_escape($brandLogo); ?>" alt="Bestlink College logo">
            <div>
                <div class="sidebar-brand-title">Bestlink College</div>
                <div class="sidebar-brand-sub">Guidance Office</div>
                <div class="sidebar-brand-chip">Student Wellness Command Center</div>
            </div>
        </div>

        <div class="sidebar-panel">
            <div class="sidebar-panel-label">Office Status</div>
            <div class="sidebar-panel-title">Counseling, intervention, student records, and integration routing in one connected workspace.</div>
        </div>

        <?php foreach (guidance_nav_items() as $item): ?>
            <a class="<?php echo $activeNav === $item['label'] ? 'active' : ''; ?>" href="<?php echo guidance_escape($item['href']); ?>">
                <?php echo guidance_escape($item['label']); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="main module-shell">
        <section class="module-hero-panel">
            <div class="module-hero-main">
                <div class="hero-badge"><?php echo guidance_escape($activeNav); ?></div>
                <h1><?php echo guidance_escape($pageTitle); ?></h1>
                <p><?php echo guidance_escape($pageDescription); ?></p>
            </div>

            <?php if ($stats): ?>
                <div class="module-stat-row">
                    <?php foreach ($stats as $stat): ?>
                        <article class="module-stat-card">
                            <div class="summary-label"><?php echo guidance_escape($stat['label'] ?? ''); ?></div>
                            <div class="module-stat-value"><?php echo guidance_escape($stat['value'] ?? '0'); ?></div>
                            <?php if (!empty($stat['note'])): ?>
                                <div class="module-stat-note"><?php echo guidance_escape($stat['note']); ?></div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($actions): ?>
                <div class="module-action-row">
                    <?php foreach ($actions as $action): ?>
                        <a
                            class="<?php echo guidance_escape($action['class'] ?? 'btn-secondary'); ?>"
                            href="<?php echo guidance_escape($action['href'] ?? '#'); ?>"
                        >
                            <?php echo guidance_escape($action['label'] ?? 'Action'); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($flow): ?>
                <div class="module-flow-row">
                    <?php foreach ($flow as $item): ?>
                        <span class="module-flow-chip"><?php echo guidance_escape($item); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php
}

function guidance_render_shell_end(): void
{
    echo '</div>';
}

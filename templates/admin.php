<?php
/** @var array $_ Template variables: allowed_group, activity_max_age_days */
?>
<div id="audit-export-admin" class="section">
    <h2><?php p($l->t('ITdesign Audit Exporter App')); ?></h2>
    <p class="settings-hint">
        <?php p($l->t('All /apps/audit_export/api/v1/export/* endpoints require an admin account or membership in the allowed group.')); ?>
    </p>
    <table class="grid">
        <thead><tr><th><?php p($l->t('Setting')); ?></th><th><?php p($l->t('Current value')); ?></th><th><?php p($l->t('Change with')); ?></th></tr></thead>
        <tbody>
            <tr>
                <td><?php p($l->t('Allowed group (members may call the export API)')); ?></td>
                <td><code><?php p($_['allowed_group']); ?></code></td>
                <td><code>occ config:app:set audit_export allowed_group --value &lt;group&gt;</code></td>
            </tr>
            <tr>
                <td><?php p($l->t('Activity log max age (days)')); ?></td>
                <td><code><?php p($_['activity_max_age_days']); ?></code></td>
                <td><code>occ config:app:set audit_export activity_max_age_days --value &lt;days&gt;</code></td>
            </tr>
        </tbody>
    </table>
    <p class="settings-hint">
        <?php p($l->t('The values are read on every request; no restart is needed. Current values are also reported by GET /apps/audit_export/api/v1/export/status.')); ?>
    </p>
</div>

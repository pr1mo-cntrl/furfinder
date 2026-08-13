<?php
// Shared by admin.php (initial page render) and admin_applications_fetch.php
// (AJAX polling) so both build the exact same table row markup from one place.
function renderApplicationRow($row) {
    $safeData = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');

    $clean_status = str_replace('_Seen', '', $row['status']);
    $statusClass = strtolower($clean_status);
    if ($statusClass == 'acknowledged') { $statusClass = 'rejected'; }

    ob_start();
    ?>
    <tr class="app-row status-<?php echo htmlspecialchars($statusClass); ?>">
        <td style="font-weight:bold; color:var(--primary-color);"><?php echo htmlspecialchars($row['pet_name']); ?></td>
        <td>
            <strong><?php echo htmlspecialchars($row['fullname']); ?></strong><br>
            <small><i class="fas fa-phone"></i> <?php echo htmlspecialchars($row['contact']); ?></small><br>
            <small><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($row['address']); ?></small>
            <button type="button" class="btn-view" onclick="viewAppDetails(<?php echo $safeData; ?>)">
                <i class="fas fa-eye"></i> View Answers
            </button>
        </td>
        <td>
            <?php if(isset($row['barangay_cert']) && $row['barangay_cert']): ?>
                <a href="<?php echo htmlspecialchars($row['barangay_cert']); ?>" target="_blank" class="doc-link"><i class="fas fa-file-contract"></i> View Brgy Cert</a>
            <?php else: ?>
                <span style="color:#999; font-size:0.8rem;">No Brgy Cert</span><br>
            <?php endif; ?>

            <?php if(isset($row['valid_id']) && $row['valid_id']): ?>
                <a href="<?php echo htmlspecialchars($row['valid_id']); ?>" target="_blank" class="doc-link"><i class="fas fa-id-card"></i> View Valid ID</a>
            <?php else: ?>
                <span style="color:#999; font-size:0.8rem;">No ID</span><br>
            <?php endif; ?>

            <?php if(isset($row['cage_photo']) && $row['cage_photo']): ?>
                <a href="<?php echo htmlspecialchars($row['cage_photo']); ?>" target="_blank" class="doc-link"><i class="fas fa-home"></i> View Cage/Leash</a>
            <?php else: ?>
                <span style="color:#999; font-size:0.8rem;">No Cage Photo</span>
            <?php endif; ?>
        </td>
        <td>
            <form method="POST" class="app-status" style="display:flex; gap:5px; flex-wrap:wrap;">
                <input type="hidden" name="app_id" value="<?php echo $row['id']; ?>">
                <button type="submit" name="update_application" value="Pending" class="status-btn status-btn-pending<?php echo strpos($row['status'], 'Pending') !== false ? ' active' : ''; ?>">Pending</button>
                <button type="submit" name="update_application" value="Approved" class="status-btn status-btn-approved<?php echo strpos($row['status'], 'Approved') !== false ? ' active' : ''; ?>">Approved</button>
                <button type="submit" name="update_application" value="Rejected" class="status-btn status-btn-rejected<?php echo (strpos($row['status'], 'Rejected') !== false || $row['status'] == 'Acknowledged') ? ' active' : ''; ?>">Rejected</button>
            </form>
        </td>
        <td>
            <form method="POST" class="js-confirm" data-confirm-msg="Archive/Delete this application?">
                <input type="hidden" name="app_id" value="<?php echo $row['id']; ?>">
                <button type="submit" name="archive_application" class="btn-delete">Archive</button>
            </form>
        </td>
    </tr>
    <?php
    return ob_get_clean();
}

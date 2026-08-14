<?php
// Builds the notification markup rendered into index.php.
function buildUserNotifications($conn, $user_id) {
    $html = '';
    $count = 0;

    $check_status = $conn->query("SELECT * FROM applications WHERE user_id = '$user_id' AND status IN ('Pending', 'Approved', 'Rejected')");

    if ($check_status && $check_status->rowCount() > 0) {
        while ($app = $check_status->fetch(PDO::FETCH_ASSOC)) {
            $app_id = $app['id'];
            $status = $app['status'];
            $count++;

            if ($status == 'Pending') {
                $html .= '
                <div class="notif-item notif-pending">
                    <strong>⏳ Application Pending:</strong> Your adoption application is currently under review by the CVAO team. We will update you here as soon as a decision is made!
                </div>';
            }
            elseif ($status == 'Approved') {
                $html .= '
                <div class="notif-item notif-approved">
                    <h4 style="margin-top: 0;">🎉 Application Approved!</h4>
                    <p style="margin-bottom: 10px;">Your adoption application has been reviewed and approved. Please proceed to the Baguio City Veterinary and Agriculture Office (CVAO) for your physical screening and interview.</p>
                    <form method="POST" action="index.php">
                        <input type="hidden" name="app_id" value="' . $app_id . '">
                        <button type="submit" name="dismiss_notification" class="notif-dismiss-btn notif-dismiss-approved">Okay, got it!</button>
                    </form>
                </div>';
            }
            elseif ($status == 'Rejected') {
                $html .= '
                <div class="notif-item notif-rejected">
                    <h4 style="margin-top: 0;">❌ Application Update</h4>
                    <p style="margin-bottom: 10px;">We regret to inform you that your recent adoption application was not approved by the CVAO at this time. Thank you for your interest in providing a home for our shelter pets.</p>
                    <form method="POST" action="index.php">
                        <input type="hidden" name="app_id" value="' . $app_id . '">
                        <button type="submit" name="dismiss_notification" class="notif-dismiss-btn notif-dismiss-rejected">Dismiss</button>
                    </form>
                </div>';
            }
        }
    }

    return [$html, $count];
}

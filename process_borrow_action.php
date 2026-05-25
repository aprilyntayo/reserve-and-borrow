<?php
session_start();
header('Content-Type: application/json');

if ($_SESSION['user_role'] !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data   = json_decode(file_get_contents('php://input'), true);
$id     = (int) $data['id'];
$action = $data['action'];
$title  = $_SESSION['signatory_title'] ?? null;

// Map each signatory title to their allowed action
$allowed = [
    'MMIT_Director' => 'note',
    'Dept_Head'     => 'verify',
    'VP_Admin'      => 'approve',
];

if ($action === 'reject') {
    // Any super_admin can reject at their stage
    $sql = match($title) {
        'MMIT_Director' => "UPDATE borrows SET mmit_director_status='Rejected', status='Rejected' WHERE id=?",
        'Dept_Head'     => "UPDATE borrows SET dept_head_status='Rejected', status='Rejected' WHERE id=?",
        'VP_Admin'      => "UPDATE borrows SET vp_admin_status='Rejected', status='Rejected' WHERE id=?",
        default         => null
    };
} elseif (isset($allowed[$title]) && $allowed[$title] === $action) {
    if ($action === 'note') {
        $sql = "UPDATE borrows SET mmit_director_status='Noted', mmit_director_noted_at=NOW() WHERE id=?";

    } elseif ($action === 'verify') {
        $dept_head_name = htmlspecialchars($data['dept_head_name'] ?? '');
        $sql = $conn->prepare(
            "UPDATE borrows SET dept_head_status='Verified', dept_head_name=?, dept_head_verified_at=NOW() WHERE id=?"
        );
        $sql->bind_param('si', $dept_head_name, $id);
        $sql->execute();
        echo json_encode(['success' => true]);
        exit();

    } elseif ($action === 'approve') {
        // Final approval — also flip the main status to Approved
        $sql = "UPDATE borrows SET vp_admin_status='Approved', vp_admin_approved_at=NOW(), status='Approved' WHERE id=?";
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Action not permitted for your role.']);
    exit();
}

if ($sql) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}
?>
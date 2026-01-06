<?php
/**
 * SweetAlert Helper - Show alerts using SweetAlert2
 * Usage: showSweetAlert($messageType, $message)
 */

function showSweetAlert($type, $message) {
    $iconMap = [
        'success' => 'success',
        'danger' => 'error',
        'warning' => 'warning',
        'info' => 'info'
    ];
    
    $titleMap = [
        'success' => 'Success!',
        'danger' => 'Error!',
        'warning' => 'Warning!',
        'info' => 'Notice'
    ];
    
    $icon = $iconMap[$type] ?? 'info';
    $title = $titleMap[$type] ?? 'Notification';
    
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: '{$icon}',
                title: " . json_encode($title) . ",
                text: " . json_encode($message) . ",
                timer: 3000,
                timerProgressBar: true,
                showConfirmButton: true,
                confirmButtonColor: '#667eea'
            });
        });
    </script>";
}


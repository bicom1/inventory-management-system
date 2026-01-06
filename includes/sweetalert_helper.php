<?php
/**
 * SweetAlert Helper Functions
 * Use this to show SweetAlert messages from PHP
 */

function showSweetAlert($type, $title, $message, $timer = 3000) {
    $iconMap = [
        'success' => 'success',
        'danger' => 'error',
        'warning' => 'warning',
        'info' => 'info'
    ];
    
    $icon = $iconMap[$type] ?? 'info';
    
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: '{$icon}',
                title: " . json_encode($title) . ",
                text: " . json_encode($message) . ",
                timer: {$timer},
                timerProgressBar: true,
                showConfirmButton: true,
                confirmButtonColor: '#667eea'
            });
        });
    </script>";
}


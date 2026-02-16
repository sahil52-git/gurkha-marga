<?php
/**
 * Email Sender for 2FA
 * Gurkha Marga - Army Recruitment Platform
 */

class EmailSender {
    
    /**
     * Send 2FA code via email using PHP mail() function
     * For production, use PHPMailer or SendGrid
     */
    public static function send2FACode($toEmail, $code, $userName) {
        $subject = "Gurkha Marga - Your 2FA Verification Code";
        
        $htmlMessage = self::get2FAEmailTemplate($code, $userName);
        
        // Headers
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: Gurkha Marga <noreply@gurkhm

arga.com>\r\n";
        $headers .= "Reply-To: sahilshrestha741@gmail.com\r\n";
        
        // Send email
        $sent = mail($toEmail, $subject, $htmlMessage, $headers);
        
        if (!$sent) {
            error_log("Failed to send 2FA email to: $toEmail");
        }
        
        return $sent;
    }
    
    /**
     * Get email template for 2FA code
     */
    private static function get2FAEmailTemplate($code, $userName) {
        return "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <style>
        body { font-family: 'Arial', sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 40px auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%); color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 40px 30px; }
        .code-box { background: #f8f9fa; border: 2px solid #3b82f6; border-radius: 8px; padding: 20px; text-align: center; margin: 30px 0; }
        .code { font-size: 36px; font-weight: bold; color: #3b82f6; letter-spacing: 8px; font-family: 'Courier New', monospace; }
        .info { color: #666; line-height: 1.6; margin: 20px 0; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #666; font-size: 12px; border-top: 1px solid #dee2e6; }
        .button { display: inline-block; padding: 12px 30px; background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; font-weight: bold; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🔐 Two-Factor Authentication</h1>
        </div>
        <div class='content'>
            <p class='info'>Hello <strong>" . htmlspecialchars($userName) . "</strong>,</p>
            <p class='info'>You have requested to log in to your Gurkha Marga admin account. Please use the verification code below to complete your login:</p>
            
            <div class='code-box'>
                <div style='color: #666; font-size: 14px; margin-bottom: 10px;'>Your Verification Code</div>
                <div class='code'>" . htmlspecialchars($code) . "</div>
            </div>
            
            <p class='info'>This code will expire in <strong>10 minutes</strong> for security reasons.</p>
            
            <div class='warning'>
                <strong>⚠️ Security Notice:</strong><br>
                If you did not request this code, please ignore this email or contact support immediately at sahilshrestha741@gmail.com
            </div>
            
            <p class='info'>Thank you for using Gurkha Marga!</p>
        </div>
        <div class='footer'>
            <p>This is an automated message from Gurkha Marga</p>
            <p>© 2026 Gurkha Marga. All rights reserved.</p>
            <p>Need help? Contact us at sahilshrestha741@gmail.com</p>
        </div>
    </div>
</body>
</html>
        ";
    }
    
    /**
     * Send welcome email to new users
     */
    public static function sendWelcomeEmail($toEmail, $userName) {
        $subject = "Welcome to Gurkha Marga!";
        
        $htmlMessage = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 40px auto; background: white; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); color: #0f172a; padding: 30px; text-align: center; }
        .content { padding: 40px 30px; }
        .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🎖️ Welcome to Gurkha Marga!</h1>
        </div>
        <div class='content'>
            <p>Hello <strong>" . htmlspecialchars($userName) . "</strong>,</p>
            <p>Welcome to Gurkha Marga - Your Path to Army Recruitment!</p>
            <p>We're excited to have you join our community of aspiring Gurkhas.</p>
            <p>Start your journey today by exploring our training programs and resources.</p>
            <p style='margin-top: 30px;'>Best of luck!</p>
            <p><strong>The Gurkha Marga Team</strong></p>
        </div>
        <div class='footer'>
            <p>© 2026 Gurkha Marga. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
        ";
        
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: Gurkha Marga <noreply@gurkharmarga.com>\r\n";
        
        return mail($toEmail, $subject, $htmlMessage, $headers);
    }
}
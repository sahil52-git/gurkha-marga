<?php
// include "db.php";
session_start();

// Generate CSRF token for contact form
if (empty($_SESSION["csrf_token"])) {
  $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Display contact form messages
$contact_message = "";
$contact_message_type = "";
if (isset($_SESSION["contact_message"])) {
  $contact_message = $_SESSION["contact_message"];
  $contact_message_type = $_SESSION["contact_type"];
  unset($_SESSION["contact_message"]);
  unset($_SESSION["contact_type"]);
}

// Redirect to dashboard if already logged in
if (isset($_SESSION["user_id"])) {
  header("Location: /users/dashboard.php");
  exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gurkha Marga - Your Path to Army Recruitment</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../public/css/home.css">

</head>
<body>
    <!-- Sticky Navigation -->
    <nav class="navbar" id="navbar">
        <div class="container">
            <div class="nav-brand">
                <div class="logo-container">
                    <img src="\gurkha-marga\frontend\image\gurkhalogo.png" type="image" alt="Loading....">
                    <span class="brand-name">Gurkha Marga</span>
                </div>
            </div>
            <ul class="nav-menu" id="navMenu">
                <li><a href="#home" class="nav-link active">Home</a></li>
                <li><a href="#features" class="nav-link">Features</a></li>
                <li><a href="#about" class="nav-link">About</a></li>
                <li><a href="#testimonials" class="nav-link">Success Stories</a></li>
                <li><a href="#contact" class="nav-link">Contact</a></li>
                <li><a href="../auth/login.php" class="btn btn-outline-white">Login</a></li>
                <li><a href="../auth/register.php" class="btn btn-primary-gradient">Get Started</a></li>
            </ul>
            <div class="mobile-menu-toggle" id="mobileToggle">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </div>
    </nav>

    <!-- Hero Section with Animated Background -->
    <section id="home" class="hero-modern">
        <div class="hero-background">
            <div class="gradient-overlay"></div>
            <div class="animated-shapes">
                <div class="shape shape-1"></div>
                <div class="shape shape-2"></div>
                <div class="shape shape-3"></div>
                <div class="shape shape-4"></div>
            </div>
        </div>
        
        <div class="container">
            <div class="hero-content-modern">
                <!-- <div class="hero-badge">
                    <span class="badge-icon">🎖️</span>
                    <span>Trusted by Aspirants</span>
                </div> -->
                
                <h1 class="hero-title-modern">
                    Transform Your Dream Into
                    <span class="gradient-text">Reality</span>
                </h1>
<!--                 
                <p class="hero-subtitle-modern">
                    Join Nepal's #1 free platform for army recruitment preparation. 
                    Get personalized training, expert guidance, and succeed in your journey to become a Gurkha.
                </p>
                
                <div class="hero-cta-modern">
                    <a href="#eligibility" class="btn btn-hero-primary">
                        <span>Check Your Eligibility</span>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <path d="M7.5 15L12.5 10L7.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    <a href="#features" class="btn btn-hero-secondary">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2"/>
                            <path d="M10 7V10L12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <span>Watch Demo</span>
                    </a>
                </div>
                
                <div class="hero-stats-modern">
                    <div class="stat-item-modern">
                        <div class="stat-icon-wrapper">
                            <span class="stat-icon">👥</span>
                        </div>
                        <div class="stat-content">
                            <h3>10+</h3>
                            <p>Active Users</p>
                        </div>
                    </div>
                    <div class="stat-item-modern">
                        <div class="stat-icon-wrapper">
                            <span class="stat-icon">💪</span>
                        </div>
                        <div class="stat-content">
                            <h3>50+</h3>
                            <p>Exercise Guides</p>
                        </div>
                    </div>
                     <div class="stat-item-modern">
                        <div class="stat-icon-wrapper">
                            <span class="stat-icon"></span>
                        </div> 
                        <div class="stat-content">
                            <h3>99%</h3>
                            <p>Free Forever</p>
                        </div> 
                    </div>
                    <div class="stat-item-modern">
                        <div class="stat-icon-wrapper">
                            <span class="stat-icon">⭐</span>
                        </div>
                        <div class="stat-content">
                            <h3>4.9/5</h3>
                            <p>User Rating</p>
                        </div>
                    </div>
                </div>
            </div> -->
        </div>
        
        <div class="scroll-indicator">
            <div class="scroll-icon"></div>
            <span>Scroll to explore</span>
        </div>
    </section>

    <!-- Eligibility Checker Section -->
    <section id="eligibility" class="eligibility-checker">
        <div class="container">
            <div class="checker-wrapper">
                <div class="checker-header">
                    <h2 class="checker-title">Check Your <span class="gradient-text">Eligibility</span></h2>
                    <p class="checker-subtitle">Find out if you meet the age requirements for army recruitment</p>
                </div>

                <form class="checker-form" id="eligibilityForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="birthYear">Birth Year *</label>
                            <input type="number" id="birthYear" name="birthYear" placeholder="Enter year (e.g., 2005)" min="1980" max="2020" required>
                        </div>
                        <div class="form-group">
                            <label for="birthMonth">Birth Month *</label>
                            <select id="birthMonth" name="birthMonth" required>
                                <option value="">Select Month</option>
                                <option value="1">January</option>
                                <option value="2">February</option>
                                <option value="3">March</option>
                                <option value="4">April</option>
                                <option value="5">May</option>
                                <option value="6">June</option>
                                <option value="7">July</option>
                                <option value="8">August</option>
                                <option value="9">September</option>
                                <option value="10">October</option>
                                <option value="11">November</option>
                                <option value="12">December</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="birthDay">Birth Day *</label>
                            <input type="number" id="birthDay" name="birthDay" placeholder="Day (1-31)" min="1" max="31" required>
                        </div>
                        <div class="form-group">
                            <label for="targetForce">Target Force *</label>
                            <select id="targetForce" name="targetForce" required>
                                <option value="">Select Force</option>
                                <option value="british">British Army</option>
                                <option value="nepal">Nepal Army</option>
                                <option value="indian">Indian Army</option>
                                <option value="singapore">Singapore Police Force</option>
                                <option value="french">French Foreign Legion</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn-check-eligibility">
                        <span>Check My Eligibility</span>
                    </button>
                </form>

                <div id="eligibilityResult" class="eligibility-result"></div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="features-modern">
        <div class="container">
            <div class="section-header-modern">
                <span class="section-badge">FEATURES</span>
                <h2 class="section-title-modern">Everything You Need to <span class="gradient-text">Succeed</span></h2>
                <p class="section-subtitle-modern">Comprehensive tools and resources to prepare you for army recruitment</p>
            </div>
            
            <div class="features-grid-modern">
                <div class="feature-card-modern">
                    <div class="feature-icon-modern">
                        <div class="icon-bg"></div>
                        <span>📋</span>
                    </div>
                    <h3>Eligibility Checker</h3>
                    <p>Instantly verify if you meet the height, weight, and age requirements for your target service</p>
                    <a href="#eligibility" class="feature-link">Learn more →</a>
                </div>
                
                <div class="feature-card-modern">
                    <div class="feature-icon-modern">
                        <div class="icon-bg"></div>
                        <span>💪</span>
                    </div>
                    <h3>Smart Training Plans</h3>
                    <p>AI-powered personalized workout routines based on your fitness level and goals</p>
                    <a href="#" class="feature-link">Learn more →</a>
                </div>
                
                <div class="feature-card-modern">
                    <div class="feature-icon-modern">
                        <div class="icon-bg"></div>
                        <span>🥗</span>
                    </div>
                    <h3>Nutrition Guide</h3>
                    <p>Budget-friendly meal plans designed to fuel your training and optimize performance</p>
                    <a href="#" class="feature-link">Learn more →</a>
                </div>
                
                <div class="feature-card-modern">
                    <div class="feature-icon-modern">
                        <div class="icon-bg"></div>
                        <span>📚</span>
                    </div>
                    <h3>Video Library</h3>
                    <p>Step-by-step exercise tutorials with proper form and technique demonstrations</p>
                    <a href="#" class="feature-link">Learn more →</a>
                </div>
                
                <div class="feature-card-modern">
                    <div class="feature-icon-modern">
                        <div class="icon-bg"></div>
                        <span>📊</span>
                    </div>
                    <h3>Progress Tracking</h3>
                    <p>Visual analytics to monitor your improvement and stay motivated every day</p>
                    <a href="#" class="feature-link">Learn more →</a>
                </div>
                
                <div class="feature-card-modern">
                    <div class="feature-icon-modern">
                        <div class="icon-bg"></div>
                        <span>📄</span>
                    </div>
                    <h3>Document Guide</h3>
                    <p>Complete checklist of required documents with step-by-step preparation timeline</p>
                    <a href="#" class="feature-link">Learn more →</a>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="about" class="about-modern">
        <div class="container">
            <div class="about-grid-modern">
                <div class="about-content-modern">
                    <span class="section-badge">ABOUT US</span>
                    <h2 class="section-title-modern">Why Choose <span class="gradient-text">Gurkha Marga</span>?</h2>
                    <p class="about-description">We understand the struggles aspiring soldiers face. That's why we created a completely free platform to democratize army recruitment preparation.</p>
                    
                    <div class="about-features">
                        <div class="about-feature-item">
                            <div class="check-icon">✓</div>
                            <div>
                                <h4>100% Free Access</h4>
                                <p>No hidden costs, no premium tiers. Everything is free forever.</p>
                            </div>
                        </div>
                        <div class="about-feature-item">
                            <div class="check-icon">✓</div>
                            <div>
                                <h4>Expert Guidance</h4>
                                <p>Training plans designed by former military personnel and fitness experts.</p>
                            </div>
                        </div>
                        <div class="about-feature-item">
                            <div class="check-icon">✓</div>
                            <div>
                                <h4>Proven Results</h4>
                                <p>500+ aspirants have successfully prepared using our platform.</p>
                            </div>
                        </div>
                        <div class="about-feature-item">
                            <div class="check-icon">✓</div>
                            <div>
                                <h4>Community Support</h4>
                                <p>Connect with fellow aspirants and share your journey.</p>
                            </div>
                        </div>
                    </div>
                    
                    <a href="register.php" class="btn btn-primary-gradient">Start Your Journey</a>
                </div>
                
                <div class="about-image-modern">
                    <div class="image-wrapper">
                        <img src="assets/images/training.jpg" alt="Training" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22600%22 height=%22700%22%3E%3Cdefs%3E%3ClinearGradient id=%22grad%22 x1=%220%25%22 y1=%220%25%22 x2=%22100%25%22 y2=%22100%25%22%3E%3Cstop offset=%220%25%22 style=%22stop-color:%232c5f2d;stop-opacity:1%22 /%3E%3Cstop offset=%22100%25%22 style=%22stop-color:%231a3a1b;stop-opacity:1%22 /%3E%3C/linearGradient%3E%3C/defs%3E%3Crect fill=%22url(%23grad)%22 width=%22600%22 height=%22700%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 font-family=%22Arial%22 font-size=%2224%22 fill=%22%23fbbf24%22 text-anchor=%22middle%22 dy=%22.3em%22%3ETraining Excellence%3C/text%3E%3C/svg%3E'">
                        <div class="image-overlay"></div>
                    </div>
                    <div class="floating-stat">
                        <span class="stat-number">98%</span>
                        <span class="stat-label">Success Rate</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonials Section -->
    <section id="testimonials" class="testimonials-modern">
        <div class="container">
            <div class="section-header-modern">
                <span class="section-badge">SUCCESS STORIES</span>
                <h2 class="section-title-modern">What Our <span class="gradient-text">Warriors</span> Say</h2>
                <p class="section-subtitle-modern">Real stories from real people who achieved their dreams</p>
            </div>
            
            <div class="testimonials-grid">
                <div class="testimonial-card">
                    <div class="testimonial-header">
                        <div class="testimonial-avatar">
                            <span>RG</span>
                        </div>
                        <div class="testimonial-info">
                            <h4>Rajesh Gurung</h4>
                            <p>British Gurkha - 2024</p>
                        </div>
                        <div class="quote-icon">"</div>
                    </div>
                    <p class="testimonial-text">Gurkha Marga changed my life! The structured training and diet plans helped me pass the physical tests with flying colors.</p>
                    <div class="testimonial-rating">⭐⭐⭐⭐⭐</div>
                </div>
                
                <div class="testimonial-card">
                    <div class="testimonial-header">
                        <div class="testimonial-avatar">
                            <span>ST</span>
                        </div>
                        <div class="testimonial-info">
                            <h4>Raj Rasaily</h4>
                            <p>Nepal Army - 2024</p>
                        </div>
                        <div class="quote-icon">"</div>
                    </div>
                    <p class="testimonial-text">Best free resource available! The eligibility checker helped me understand requirements clearly, and progress tracking kept me motivated.</p>
                    <div class="testimonial-rating">⭐⭐⭐⭐⭐</div>
                </div>
                
                <div class="testimonial-card">
                    <div class="testimonial-header">
                        <div class="testimonial-avatar">
                            <span>BL</span>
                        </div>
                        <div class="testimonial-info">
                            <h4>Bikash Limbu</h4>
                            <p>Indian Gurkha - 2024</p>
                        </div>
                        <div class="quote-icon">"</div>
                    </div>
                    <p class="testimonial-text">I couldn't afford expensive training centers. Gurkha Marga gave me everything I needed for free. Forever grateful!</p>
                    <div class="testimonial-rating">⭐⭐⭐⭐⭐</div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-modern">
        <div class="container">
            <div class="cta-content-modern">
                <h2>Ready to Start Your Journey?</h2>
                <p>Join 500+ aspirants who are already training with us</p>
                <a href="register.php" class="btn btn-cta-large">Create Free Account</a>
                <p class="cta-note">No credit card required • Start in 30 seconds</p>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact" class="contact-modern">
        <div class="container">
            <div class="section-header-modern">
                <span class="section-badge">CONTACT US</span>
                <h2 class="section-title-modern">Get In <span class="gradient-text">Touch</span></h2>
                <p class="section-subtitle-modern">Send us your queries and we'll get back to you soon.</p>
            </div>

            <div class="contact-form-wrapper">
                <?php if ($contact_message): ?>
                    <div class="alert alert-<?php echo htmlspecialchars(
                      $contact_message_type,
                    ); ?>">
                        <?php if ($contact_message_type === "success"): ?>
                            ✓
                        <?php else: ?>
                            ⚠
                        <?php endif; ?>
                        <?php echo htmlspecialchars($contact_message); ?>
                    </div>
                <?php endif; ?>

                <form action="process-contact.php" method="POST" id="contactForm" novalidate>
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION[
                      "csrf_token"
                    ]; ?>">

                    <div class="form-group">
                        <label for="username">Your Name *</label>
                        <input 
                            type="text" 
                            id="username"
                            name="username" 
                            placeholder="Enter your full name" 
                            required
                            minlength="2"
                            maxlength="100"
                            value="<?php echo isset(
                              $_SESSION["old_input"]["username"],
                            )
                              ? htmlspecialchars(
                                $_SESSION["old_input"]["username"],
                              )
                              : ""; ?>"
                        >
                        <span class="error-message" id="username-error"></span>
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <input 
                            type="email" 
                            id="email"
                            name="email" 
                            placeholder="your.email@example.com" 
                            required
                            maxlength="255"
                            value="<?php echo isset(
                              $_SESSION["old_input"]["email"],
                            )
                              ? htmlspecialchars(
                                $_SESSION["old_input"]["email"],
                              )
                              : ""; ?>"
                        >
                        <span class="error-message" id="email-error"></span>
                    </div>

                    <div class="form-group">
                        <label for="phone">Contact Number *</label>
                        <input 
                            type="tel" 
                            id="phone"
                            name="phone" 
                            placeholder="Your contact number" 
                            required
                            pattern="[0-9]{10,15}"
                            maxlength="15"
                            value="<?php echo isset(
                              $_SESSION["old_input"]["phone"],
                            )
                              ? htmlspecialchars(
                                $_SESSION["old_input"]["phone"],
                              )
                              : ""; ?>"
                        >
                        <span class="error-message" id="phone-error"></span>
                    </div>

                    <div class="form-group">
                        <label for="message">Your Message *</label>
                        <textarea 
                            name="message" 
                            id="message" 
                            placeholder="Type your query here..." 
                            required
                            minlength="10"
                            maxlength="1000"
                            rows="5"
                        ><?php echo isset($_SESSION["old_input"]["message"])
                          ? htmlspecialchars($_SESSION["old_input"]["message"])
                          : ""; ?></textarea>
                        <span class="error-message" id="message-error"></span>
                    </div>

                    <button type="submit" class="btn-submit" id="submitBtn">
                        <span class="btn-text">Send Message</span>
                        <span class="btn-loader">Sending...</span>
                    </button>
                </form>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer-modern">
        <div class="container">
            <div class="footer-content-modern">
                <div class="footer-brand">
                    <div class="footer-logo">
                        <img src="../image/gurkhalogo.png" alt="Gurkha Marga Logo">
                        <span>Gurkha Marga</span>
                    </div>
                    <p>Empowering youth with the right guidance for army recruitment. Your success is our mission.</p>
                </div>
                
                <div class="footer-column">
                    <h4>Platform</h4>
                    <ul>
                        <li><a href="#features">Features</a></li>
                        <li><a href="#about">About</a></li>
                        <li><a href="register.php">Register</a></li>
                        <li><a href="login.php">Login</a></li>
                    </ul>
                </div>
                
                <div class="footer-column">
                    <h4>Resources</h4>
                    <ul>
                        <li><a href="#">FAQ</a></li>
                        <li><a href="#">Training Tips</a></li>
                        <li><a href="#testimonials">Success Stories</a></li>
                        <li><a href="#">Blog</a></li>
                    </ul>
                </div>
                
                <div class="footer-column">
                    <h4>Legal</h4>
                    <ul>
                        <li><a href="#">Privacy Policy</a></li>
                        <li><a href="#">Terms of Service</a></li>
                        <li><a href="#">Cookie Policy</a></li>
                    </ul>
                </div>
            </div>
            
            <div class="footer-bottom-modern">
                <p>&copy; 2026 Gurkha Marga. All rights reserved. Built for providing guidance for Gurkhas 🪖</p>
                <div class="footer-social">
                    <a href="https://www.facebook.com/SahilShrestha" aria-label="Facebook" target="_blank">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                        </svg>
                    </a>
                    <a href="https://www.instagram.com/shresthasahil7/" aria-label="Instagram" target="_blank" rel="noopener noreferrer">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
                        </svg>
                    </a>
                    <a href="#" aria-label="YouTube">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    </footer>

    <script>
        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.getElementById('navbar');
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // Mobile menu toggle
        const mobileToggle = document.getElementById('mobileToggle');
        const navMenu = document.getElementById('navMenu');

        mobileToggle.addEventListener('click', function() {
            navMenu.classList.toggle('active');
        });

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                    // Close mobile menu if open
                    navMenu.classList.remove('active');
                }
            });
        });

        // Active nav link on scroll
        window.addEventListener('scroll', function() {
            const sections = document.querySelectorAll('section[id]');
            const scrollY = window.pageYOffset;

            sections.forEach(current => {
                const sectionHeight = current.offsetHeight;
                const sectionTop = current.offsetTop - 100;
                const sectionId = current.getAttribute('id');
                
                if (scrollY > sectionTop && scrollY <= sectionTop + sectionHeight) {
                    document.querySelectorAll('.nav-link').forEach(link => {
                        link.classList.remove('active');
                        if (link.getAttribute('href') === `#${sectionId}`) {
                            link.classList.add('active');
                        }
                    });
                }
            });
        });

        // Eligibility Checker
        document.getElementById('eligibilityForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const year = parseInt(document.getElementById('birthYear').value);
            const month = parseInt(document.getElementById('birthMonth').value);
            const day = parseInt(document.getElementById('birthDay').value);
            const targetForce = document.getElementById('targetForce').value;
            
            // Calculate age
            const birthDate = new Date(year, month - 1, day);
            const today = new Date();
            let age = today.getFullYear() - birthDate.getFullYear();
            const monthDiff = today.getMonth() - birthDate.getMonth();
            
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }
            
            // Age criteria
            const criteria = {
                british: { min: 17, max: 21, name: "British Army" },
                nepal: { min: 18, max: 21, name: "Nepal Army" },
                indian: { min: 17, max: 21, name: "Indian Army" },
                singapore: { min: 18, max: 21, name: "Singapore Police Force" },
                french: { min: 17, max: 35, name: "French Foreign Legion" }
            };
            
            const resultDiv = document.getElementById('eligibilityResult');
            
            if (!targetForce) {
                resultDiv.innerHTML = `
                    <div class="result-not-eligible">
                        <div class="result-icon">⚠️</div>
                        <h3 class="result-title">Please Select a Force</h3>
                        <p class="result-message">Choose your target force to check eligibility.</p>
                    </div>
                `;
                resultDiv.style.display = 'block';
                return;
            }
            
            // Check which forces the user is eligible for
            const eligibleForces = [];
            for (const [key, value] of Object.entries(criteria)) {
                if (age >= value.min && age <= value.max) {
                    eligibleForces.push(value.name);
                }
            }
            
            const selected = criteria[targetForce];
            const isEligible = age >= selected.min && age <= selected.max;
            
            if (isEligible) {
                resultDiv.innerHTML = `
                    <div class="result-eligible">
                        <div class="result-icon">🎉</div>
                        <h3 class="result-title">Congratulations! You're Eligible</h3>
                        <p class="result-message">
                            You are <strong>${age} years old</strong> and meet the age requirements for <strong>${selected.name}</strong> 
                            (Age: ${selected.min}-${selected.max} years).
                        </p>
                        ${eligibleForces.length > 1 ? `
                            <p class="result-message">You're also eligible for:</p>
                            <div class="eligible-forces">
                                ${eligibleForces.filter(f => f !== selected.name).map(f => `<span class="force-badge">${f}</span>`).join('')}
                            </div>
                        ` : ''}
                        <div class="result-actions">
                            <a href="auth/register.php" class="btn-result btn-register">Create Account & Start Training</a>
                        </div>
                    </div>
                `;
            } else {
                resultDiv.innerHTML = `
                    <div class="result-not-eligible">
                        <div class="result-icon">ℹ️</div>
                        <h3 class="result-title">Not Eligible for ${selected.name}</h3>
                        <p class="result-message">
                            You are <strong>${age} years old</strong>. The ${selected.name} requires candidates to be between 
                            <strong>${selected.min}-${selected.max} years old</strong>.
                        </p>
                        ${eligibleForces.length > 0 ? `
                            <p class="result-message">However, you're eligible for:</p>
                            <div class="eligible-forces">
                                ${eligibleForces.map(f => `<span class="force-badge">${f}</span>`).join('')}
                            </div>
                            <div class="result-actions">
                                <a href="register.php" class="btn-result btn-register">Create Account & Start Training</a>
                            </div>
                        ` : `
                            <p class="result-message">
                                You can still explore our basic exercise plans and training resources to stay fit and prepared for future opportunities!
                            </p>
                            <div class="result-actions">
                                <a href="#features" class="btn-result btn-explore">Explore Training Plans</a>
                            </div>
                        `}
                    </div>
                `;
            }
            
            resultDiv.style.display = 'block';
            resultDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });

        // Contact Form Validation
        document.getElementById('contactForm').addEventListener('submit', function(e) {
            let isValid = true;
            
            // Clear previous errors
            document.querySelectorAll('.error-message').forEach(el => el.textContent = '');
            
            // Validate username
            const username = document.getElementById('username').value.trim();
            if (username.length < 2) {
                document.getElementById('username-error').textContent = 'Name must be at least 2 characters';
                isValid = false;
            }
            
            // Validate email
            const email = document.getElementById('email').value.trim();
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                document.getElementById('email-error').textContent = 'Please enter a valid email address';
                isValid = false;
            }
            
            // Validate phone
            const phone = document.getElementById('phone').value.trim();
            if (!/^[0-9]{10,15}$/.test(phone)) {
                document.getElementById('phone-error').textContent = 'Please enter a valid phone number (10-15 digits)';
                isValid = false;
            }
            
            // Validate message
            const message = document.getElementById('message').value.trim();
            if (message.length < 10) {
                document.getElementById('message-error').textContent = 'Message must be at least 10 characters';
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
            } else {
                // Show loading state
                document.querySelector('.btn-text').style.display = 'none';
                document.querySelector('.btn-loader').style.display = 'inline';
                document.getElementById('submitBtn').disabled = true;
            }
        });
    </script>
</body>
</html>

<?php // Clear old input after displaying
unset($_SESSION["old_input"]); ?>  
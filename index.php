<?php
// index.php - Landing Page
// This is the public landing page for the CBT system

require_once 'includes/config.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digital CBT System - School Examination Platform</title>

    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 25vw;
            font-weight: 900;
            color: rgba(255, 255, 255, 0.03);
            z-index: 0;
            pointer-events: none;
            user-select: none;
            white-space: nowrap;
            font-family: 'Roboto', sans-serif;
        }

        .container {
            max-width: 1200px;
            width: 100%;
            z-index: 1;
            position: relative;
        }

        .header {
            text-align: center;
            margin-bottom: 50px;
        }

        .logo {
            width: 120px;
            height: 120px;
            margin: 0 auto 20px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }

        .logo i {
            font-size: 50px;
            color: white;
        }

        .welcome-title {
            color: white;
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
            line-height: 1.2;
        }

        .welcome-subtitle {
            color: rgba(255, 255, 255, 0.85);
            font-size: 1.2rem;
            font-weight: 400;
            max-width: 600px;
            margin: 0 auto 30px;
            line-height: 1.6;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 50px;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .feature-card:hover {
            transform: translateY(-10px);
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }

        .feature-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 28px;
            color: white;
        }

        .feature-title {
            color: white;
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .feature-description {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .login-section {
            text-align: center;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 40px;
            max-width: 600px;
            margin: 0 auto;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .school-selector {
            margin-bottom: 30px;
        }

        .selector-label {
            display: block;
            color: white;
            font-size: 1.1rem;
            margin-bottom: 15px;
            font-weight: 500;
        }

        .school-dropdown {
            width: 100%;
            padding: 15px 20px;
            border-radius: 50px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.1);
            color: white;
            font-size: 1rem;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            outline: none;
            transition: all 0.3s ease;
        }

        .school-dropdown:hover,
        .school-dropdown:focus {
            border-color: white;
            background: rgba(255, 255, 255, 0.15);
        }

        .school-dropdown option {
            background: #667eea;
            color: white;
        }

        .selected-school {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 20px;
            margin-top: 20px;
            display: none;
            animation: fadeIn 0.5s ease;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .selected-school.active {
            display: block;
        }

        .school-info {
            color: white;
            text-align: left;
        }

        .school-name {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 5px;
            color: white;
        }

        .school-address {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.95rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .school-contact {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .login-button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 18px 40px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-top: 20px;
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
            width: 100%;
            max-width: 300px;
        }

        .login-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(102, 126, 234, 0.4);
        }

        .login-button:active {
            transform: translateY(-1px);
        }

        .login-button i {
            font-size: 1.2rem;
        }

        .footer {
            text-align: center;
            margin-top: 50px;
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.9rem;
            padding: 20px;
        }

        .footer a {
            color: white;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer a:hover {
            color: #667eea;
        }

        .footer-logo {
            color: white;
            font-weight: 600;
            margin-top: 10px;
            font-size: 0.95rem;
            opacity: 0.7;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .pulse {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }

            100% {
                transform: scale(1);
            }
        }

        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            pointer-events: none;
        }

        .particle {
            position: absolute;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .welcome-title {
                font-size: 2.5rem;
            }

            .welcome-subtitle {
                font-size: 1.1rem;
                padding: 0 20px;
            }

            .feature-card {
                padding: 20px;
            }

            .login-section {
                padding: 30px 20px;
                margin: 0 20px;
            }

            .watermark {
                font-size: 30vw;
            }
        }

        @media (max-width: 480px) {
            .welcome-title {
                font-size: 2rem;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }
        }

        .watermark-logo {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 400px;
            height: 400px;
            opacity: 0.05;
            z-index: 0;
            pointer-events: none;
            user-select: none;
            object-fit: contain;
        }

        /* For responsive design */
        @media (max-width: 768px) {
            .watermark-logo {
                width: 300px;
                height: 300px;
            }
        }

        @media (max-width: 480px) {
            .watermark-logo {
                width: 250px;
                height: 250px;
            }
        }
    </style>
</head>

<body>
    <!-- Background Particles -->
    <div class="particles" id="particles"></div>

    <!-- Watermark Logo -->
    <img src="assets/images/logo.png" alt="CBT System Logo" class="watermark-logo">

    <div class="container">
        <div class="header">
            <div class="logo">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <h1 class="welcome-title">Digital CBT Examination System</h1>
            <p class="welcome-subtitle">
                A comprehensive Computer-Based Testing platform for schools and educational institutions.
                Streamline your examination process with our secure, reliable, and user-friendly system.
            </p>
        </div>

        <!-- Features Section -->
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-laptop-code"></i>
                </div>
                <h3 class="feature-title">Computer-Based Testing</h3>
                <p class="feature-description">
                    Conduct exams digitally with automatic grading and instant results.
                    Supports multiple question types including objective, theory, and subjective.
                </p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <h3 class="feature-title">Performance Analytics</h3>
                <p class="feature-description">
                    Detailed reports and analytics to track student performance.
                    Generate comprehensive report cards with automatic grade calculation.
                </p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <h3 class="feature-title">Secure & Reliable</h3>
                <p class="feature-description">
                    Advanced security features including exam session protection.
                    Data encryption and secure authentication for all users.
                </p>
            </div>
        </div>

        <!-- Login Section -->
        <div class="login-section">
            <h2 style="color: white; margin-bottom: 30px; font-size: 1.8rem;">Access Your School Dashboard</h2>

            <p style="color: rgba(255, 255, 255, 0.85); margin-bottom: 30px; line-height: 1.6; font-size: 1.1rem;">
                Welcome to the Digital CBT Examination System. Please proceed to the login page to access your school's dashboard.
            </p>

            <button class="login-button pulse" onclick="proceedToLogin()" id="loginBtn">
                <i class="fas fa-sign-in-alt"></i>
                Login to your School Dashboard
            </button>


        </div>

        <!-- Footer -->
        <div class="footer">
            <p>© <?php echo date('Y'); ?> Digital CBT System. All rights reserved.</p>
            <p>Designed to enhance educational assessment and examination management.</p>
            <div class="footer-logo">
                Powered by Impact Digital Services
            </div>
        </div>
    </div>

    <script>
        // School data
        const schools = {
            'greenglade': {
                name: 'Green Glade International School',
                address: '123 Education Avenue, Lagos, Nigeria',
                phone: '+234 801 234 5678',
                email: 'info@greenglade.edu.ng'
            },
            'sunrise': {
                name: 'Sunrise Academy',
                address: '456 Learning Road, Abuja, Nigeria',
                phone: '+234 802 345 6789',
                email: 'admin@sunriseacademy.edu.ng'
            },
            'excellence': {
                name: 'Excellence College',
                address: '789 Knowledge Street, Port Harcourt, Nigeria',
                phone: '+234 803 456 7890',
                email: 'contact@excellencecollege.edu.ng'
            },
            'harmony': {
                name: 'Harmony Secondary School',
                address: '321 Wisdom Boulevard, Ibadan, Nigeria',
                phone: '+234 804 567 8901',
                email: 'support@harmonyschool.edu.ng'
            },
            'prestige': {
                name: 'Prestige High School',
                address: '654 Success Avenue, Enugu, Nigeria',
                phone: '+234 805 678 9012',
                email: 'info@prestigehigh.edu.ng'
            },
            'knowledge': {
                name: 'Knowledge Academy',
                address: '987 Progress Road, Benin City, Nigeria',
                phone: '+234 806 789 0123',
                email: 'admin@knowledgeacademy.edu.ng'
            }
        };

        // Show school details when selected
        function showSchoolDetails() {
            const select = document.getElementById('schoolSelect');
            const schoolId = select.value;
            const detailsDiv = document.getElementById('schoolDetails');
            const loginBtn = document.getElementById('loginBtn');

            if (schoolId && schools[schoolId]) {
                const school = schools[schoolId];

                document.getElementById('schoolName').textContent = school.name;
                document.getElementById('schoolAddress').textContent = school.address;
                document.getElementById('schoolPhone').textContent = school.phone;
                document.getElementById('schoolEmail').textContent = school.email;

                detailsDiv.classList.add('active');
                loginBtn.disabled = false;
                loginBtn.classList.add('pulse');

                // Store selected school in localStorage for login page
                localStorage.setItem('selectedSchool', schoolId);
                localStorage.setItem('selectedSchoolName', school.name);
            } else {
                detailsDiv.classList.remove('active');
                loginBtn.disabled = true;
                loginBtn.classList.remove('pulse');
            }
        }

        // Proceed to login page
        function proceedToLogin() {
            window.location.href = 'login.php';
        }

        // Create background particles
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 50;

            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');

                // Random size and position
                const size = Math.random() * 5 + 2;
                const posX = Math.random() * 100;
                const posY = Math.random() * 100;

                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                particle.style.left = `${posX}%`;
                particle.style.top = `${posY}%`;
                particle.style.opacity = Math.random() * 0.5 + 0.1;

                // Random animation
                particle.style.animation = `float ${Math.random() * 10 + 10}s linear infinite`;

                particlesContainer.appendChild(particle);
            }

            // Add CSS for floating animation
            const style = document.createElement('style');
            style.textContent = `
                @keyframes float {
                    0%, 100% { transform: translate(0, 0) rotate(0deg); }
                    25% { transform: translate(${Math.random() * 20 - 10}px, ${Math.random() * 20 - 10}px) rotate(90deg); }
                    50% { transform: translate(${Math.random() * 20 - 10}px, ${Math.random() * 20 - 10}px) rotate(180deg); }
                    75% { transform: translate(${Math.random() * 20 - 10}px, ${Math.random() * 20 - 10}px) rotate(270deg); }
                }
            `;
            document.head.appendChild(style);
        }

        // Initialize particles on load
        document.addEventListener('DOMContentLoaded', function() {
            createParticles();

            // Add some interactivity to feature cards
            const featureCards = document.querySelectorAll('.feature-card');
            featureCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-10px) scale(1.02)';
                });

                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });

            // Auto-select a school for demo purposes
            // setTimeout(() => {
            //     document.getElementById('schoolSelect').value = 'greenglade';
            //     showSchoolDetails();
            // }, 1000);
        });

        // Add keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const loginBtn = document.getElementById('loginBtn');
                if (!loginBtn.disabled) {
                    proceedToLogin();
                }
            }
        });
    </script>
</body>

</html>
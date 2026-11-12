<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home - Milkshake & Coffee</title>
    <link rel="stylesheet" href="css/homepage.css">
</head>
<body>

<main class="main-content">
    <!-- Top Header -->
    <header class="top-header">
        <div class="header-left">
            <div class="logo-circle"></div>
        </div>

        <nav class="top-nav">
            <a href="#" class="nav-link active">Home</a> 
            <a href="order.html" class="nav-link">Order</a>
            <a href="#" class="nav-link">About Us</a>
        </nav>

        <div class="header-right dropdown">
            <button class="dropdown-btn">Account ▼</button>
            <div class="dropdown-content">
                <a href="#">Profile</a>
                <a href="#">Orders</a>
                <a href="#">Logout</a>
            </div>
        </div>
    </header>

    <!-- Featured Carousel Section -->
    <section class="featured-products">
        <div class="carousel-card">
            <button class="arrow left-arrow" onclick="changeSlide(-1)">&lsaquo;</button>

            <div class="product-image-container">
                <img id="productImg" src="image" alt="Featured Product">
            </div>

            <button class="arrow right-arrow" onclick="changeSlide(1)">&rsaquo;</button>

            <div class="carousel-dots" id="dotsContainer"></div>
        </div>

        <div class="product-details">
            <h2 id="productName">Loading...</h2>
            <p id="productDesc">Loading description...</p>
            <a href="order.html" class="order-btn">Order now!</a>
        </div>
    </section>
</main>

<script src="js/homepage.js"></script>
</body>
</html>
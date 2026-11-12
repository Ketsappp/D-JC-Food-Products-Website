const featuredProducts = [
    {
        name: "Mango Graham Overload (M1)",
        description: "[Description]",
        img: "image/mango-graham-overload.png"
    },
    {
        name: "Cookies & Cream",
        description: "[Description]",
        img: "image/cookies-and-cream.png"
    },
    {
        name: "Rocky Road",
        description: "[Description]",
        img: "image/rocky-road.png"
    },
    {
        name: "Dark Mocha",
        description: "[Description]",
        img: "image/dark-mocha.png"
    }
];

let currentIndex = 0;

function renderCarousel() {
    const product = featuredProducts[currentIndex];
    document.getElementById("productName").innerText = product.name;
    document.getElementById("productDesc").innerText = product.description;
    document.getElementById("productImg").src = product.img;
    document.getElementById("productImg").alt = product.name;

    // Render dots dynamically
    const dotsContainer = document.getElementById("dotsContainer");
    dotsContainer.innerHTML = featuredProducts.map((_, idx) =>
        `<span class="dot ${idx === currentIndex ? 'active' : ''}" onclick="goToSlide(${idx})"></span>`
    ).join('');
}

function changeSlide(direction) {
    currentIndex = (currentIndex + direction + featuredProducts.length) % featuredProducts.length;
    renderCarousel();
}

function goToSlide(index) {
    currentIndex = index;
    renderCarousel();
}

// Initialize on page load
document.addEventListener("DOMContentLoaded", renderCarousel);
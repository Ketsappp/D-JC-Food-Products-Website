<?php
    // payment_success.php
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Payment Status | D&JC Food Product</title>

        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }

            body {
                background: #f6f5f3;
                color: #1a1a1a;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .payment-container {
                width: 100%;
                max-width: 480px;
                background: #ffffff;
                padding: 36px 32px;
                border-radius: 16px;
                text-align: center;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            }

            .status-icon {
                width: 68px;
                height: 68px;
                margin: 0 auto 20px;
                border-radius: 50%;
                background: #f6f0df;
                border: 2px solid #d5ba77;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 32px;
            }

            h1 {
                font-size: 25px;
                margin-bottom: 12px;
            }

            p {
                color: #777;
                font-size: 14px;
                line-height: 1.7;
                margin-bottom: 14px;
            }

            .alert-box {
                padding: 12px 16px;
                border-radius: 8px;
                margin: 20px 0;
                font-size: 14px;
                background: #fff5dc;
                color: #755b19;
            }

            .btn-primary {
                display: inline-block;
                width: 100%;
                margin-top: 8px;
                padding: 12px 18px;
                border: 1px solid #1a1a1a33;
                border-radius: 8px;
                background: #d5ba77;
                color: #1a1a1a;
                text-decoration: none;
                font-weight: 600;
                font-size: 14px;
                cursor: pointer;
                transition: background 0.2s ease;
            }

            .btn-primary:hover {
                background: #af9450;
            }

            .brand-name {
                margin-top: 24px;
                font-size: 12px;
                color: #999;
            }

            @media (max-width: 480px) {
                .payment-container {
                    padding: 28px 20px;
                }

                h1 {
                    font-size: 22px;
                }
            }
        </style>
    </head>

    <body>
        <main class="payment-container">
            <div class="status-icon" aria-hidden="true">₱</div>

            <h1>Checking Your Payment</h1>

            <p>
                Thank you for ordering from D&JC Food Product.
            </p>

            <div class="alert-box">
                Your payment status has not yet been verified.
                Please wait for confirmation.
            </div>

            <p>
                Do not make another payment for the same order.
                If your payment remains pending, please ask the store staff
                to check its status.
            </p>

            <a class="btn-primary" href="order.php">
                Back to Ordering
            </a>

            <div class="brand-name">
                D&JC Food Product
            </div>
        </main>
    </body>
    </html>

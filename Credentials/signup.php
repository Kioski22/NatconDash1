<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Sign Up</title>
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/css/bootstrap.min.css"
    rel="stylesheet"
  />
  <style>
    :root {
      --bg-color: #f4f4f4;
    }

    body {
      background-color: var(--bg-color);
      font-family: Arial, sans-serif;
    }

    .form-wrapper {
      max-width: 1000px;
      margin: 50px auto;
      background: #fff;
      border-radius: 1rem;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
      overflow: hidden;
    }

    .form-image {
      width: 100%;
      height: auto;
      object-fit: cover;
      border-top-left-radius: 1rem;
      border-top-right-radius: 1rem;
    }

    @media (min-width: 768px) {
      .form-flex {
        display: flex;
        flex-direction: row;
      }

      .form-image {
        max-width: 50%;
        border-top-right-radius: 0;
        border-bottom-left-radius: 1rem;
      }
    }

    .form-padding {
      padding: 2rem;
      flex: 1;
    }

    .form-control {
      background-color: #f9f9f9;
    }

    .message {
      text-align: center;
      font-weight: bold;
      color: red;
    }

    .eye-icon {
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      cursor: pointer;
      user-select: none;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="form-wrapper">
      <div class="form-flex">
        <img src="../image/natcon.jpg" alt="Natcon Logo" class="img-fluid form-image" />

        <div class="form-padding">
          <div class="message"></div>
          <h2 class="text-center mb-4">Sign Up</h2>
          <form id="signup-form">
            <div class="mb-3">
              <label for="name" class="form-label">Name:</label>
              <input type="text" class="form-control" id="name" name="name" required />
            </div>
            <div class="mb-3">
              <label for="email" class="form-label">Email:</label>
              <input type="email" class="form-control" id="email" name="email" required />
            </div>
            <div class="mb-3 position-relative">
              <label for="password" class="form-label">Password:</label>
              <input
                type="password"
                class="form-control"
                id="password"
                name="password"
                required
                style="padding-right: 40px"
              />
              <span class="eye-icon" onclick="togglePassword()"></span>
            </div>
            <button type="submit" class="btn btn-success w-100">Sign Up</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <script>
    function togglePassword() {
      const passwordField = document.getElementById("password");
      passwordField.type = passwordField.type === "password" ? "text" : "password";
    }
  document.getElementById("signup-form").addEventListener("submit", function (e) {
    e.preventDefault();

    const formData = new FormData(this);

    fetch("http://localhost/NatconDash1/api/api_signup.php", {
      method: "POST",
      body: formData,
    })
      .then((res) => res.json())
      .then((data) => {
        if (data.status === "success" && data.redirect) {
          // ✅ Redirect on success
          window.location.href = data.redirect;
        } else {
          // ❌ Show error message
          alert(data.message || "Something went wrong.");
        }
      })
      .catch((err) => {
        console.error("Error:", err);
        alert("Something went wrong.");
      });
  });
  </script>
</body>
</html>

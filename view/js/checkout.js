const cardFields = document.getElementById('cardFields');
const radios = document.querySelectorAll('input[name="payment"]');
const form = document.getElementById('checkoutForm');
const submitBtn = document.getElementById('submitBtn');
const loading = document.getElementById('loading');
const result = document.getElementById('result');
const quantityElement = document.getElementById("no_of_products");
const cartTotal = document.getElementById("cart_total");
const firstNameField = document.getElementById("firstName");
const lastNameField = document.getElementById("lastName");
const emailField = document.getElementById("email");


document.addEventListener("DOMContentLoaded", async () => {

    radios.forEach(radio => {
	radio.addEventListener('change', () => {
	  cardFields.style.display = radio.value === 'card' ? 'block' : 'none';
	});
    });

    submitBtn.addEventListener('click', confirmOrder);
    
    const user_info = await getUserInfo();

    if (firstNameField && user_info.fname) {
	firstNameField.value = user_info.fname;
    }
    if (lastNameField && user_info.lname) {
	lastNameField.value = user_info.lname;
    }
    if (emailField && user_info.email) {
	emailField.value = user_info.email;
    }

    const quantity = await getItemQuantity();
    const total = await getCartTotal();

    if (quantityElement) {
	quantityElement.innerHTML = `Product(s): <strong>${quantity} Item(s)</strong>`;
    }

    if (cartTotal) {
	cartTotal.innerHTML = `Total: <strong>Ksh. ${total}</strong>`;
    }
});


async function getUserInfo() {
    const response = await fetch("/index.php/user/userinfo");
    const data = await response.json();

    if(!data.success) {
	window.alert("First Sign In or Sign Up");

    }

   return data;
}

async function getItemQuantity() {
    const response = await fetch("/index.php/cart/get");
    const data = await response.json();

    if (!data.success) {
	return -1;
    }
    
    let quantity = 0;

    for (const product of data['cart']) {
	quantity += product.qty;
    }
    
    return quantity;
}

async function getCartTotal() {
    const response = await fetch("/index.php/cart/total")
    const data = await response.json();

    if(!data.success) {
	return -1;
    }

    return data.total;
}

async function confirmOrder() {

    if (!form.checkValidity()) {
	form.reportValidity();
	return;
    }

    const method = document.querySelector('input[name="payment"]:checked')?.value;
    if (method === 'mpesa') {
	return payWithMpesa();
    }

    loading.style.display = 'block';
    result.style.display = 'none';

    const resp = await fetch("/index.php/cart/confirm");
    resp.json()
	.then(data => {

	if (data.success) {
	    window.alert("Order Confirmed")
	    result.textContent = "✅ Order Placed Successfully!";
	    loading.style.display = 'none';
	    window.location.href = "/";
	}
	else {
	    result.textContent = "❌ Payment Failed. Try Again."
	    loading.style.display = 'none';
    }
    });

}

// --- M-Pesa STK Push -------------------------------------------------------

function showStatus(message, busy) {
    loading.textContent = message;
    loading.style.display = busy ? 'block' : 'none';
    result.textContent = busy ? '' : message;
    result.style.display = busy ? 'none' : 'block';
}

async function payWithMpesa() {
    const phone = document.getElementById('phone').value.trim();
    submitBtn.disabled = true;
    showStatus("Sending payment request to your phone...", true);

    let data;
    try {
	const resp = await fetch("/index.php/payment/stkpush", {
	    method: "POST",
	    headers: { "Content-Type": "application/json", "Accept": "application/json" },
	    body: JSON.stringify({ phone }),
	});
	data = await resp.json();
    } catch (e) {
	console.error("stkpush:", e);
	data = { success: false, detail: "Network error. Check your connection and try again." };
    }

    if (!data.success) {
	showStatus("❌ " + (data.detail || "Could not start M-Pesa payment."), false);
	submitBtn.disabled = false;
	return;
    }

    showStatus("📲 " + data.message + " Waiting for confirmation...", true);
    pollPayment(data.checkout_request_id);
}

// Polls every 3s for up to 2 minutes. The server falls back to asking
// Safaricom directly if their callback is slow.
async function pollPayment(checkoutId, attempt = 0) {
    if (attempt >= 40) {
	showStatus("⌛ We haven't received confirmation yet. If you paid, your order will update shortly.", false);
	submitBtn.disabled = false;
	return;
    }

    try {
	const resp = await fetch("/index.php/payment/status/" + encodeURIComponent(checkoutId));
	const data = await resp.json();

	if (data.success && data.status === 'success') {
	    showStatus(`✅ Payment received (M-Pesa ref ${data.receipt || ''}). Order #${data.order_no} confirmed!`, false);
	    setTimeout(() => { window.location.href = "/"; }, 4000);
	    return;
	}
	if (data.success && data.status === 'cancelled') {
	    showStatus("❌ You cancelled the M-Pesa prompt. Try again when ready.", false);
	    submitBtn.disabled = false;
	    return;
	}
	if (data.success && data.status === 'failed') {
	    showStatus("❌ Payment failed: " + (data.detail || "please try again."), false);
	    submitBtn.disabled = false;
	    return;
	}
    } catch (e) {
	console.error("payment status:", e);
    }

    setTimeout(() => pollPayment(checkoutId, attempt + 1), 3000);
}

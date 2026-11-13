import { createApp } from 'vue';
import Checkout from './Checkout.vue';

const mount = document.getElementById('checkout');
const data = JSON.parse(document.getElementById('checkout-data').textContent);

createApp(Checkout, { initial: data.state, embed: data.embed }).mount(mount);

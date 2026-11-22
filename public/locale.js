const messages = JSON.parse(document.getElementById('interface-messages')?.textContent ?? '{}');

export function t(message, values = {}) {
    const text = Object.hasOwn(messages, message) ? messages[message] : message;
    return text.replace(/:(\w+)/g, (match, key) => Object.hasOwn(values, key) ? String(values[key]) : match);
}

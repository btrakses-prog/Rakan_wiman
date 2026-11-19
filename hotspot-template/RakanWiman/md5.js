/* RAKAN WIMAN HotSpot CHAP helper — compact RFC 1321 MD5 implementation. */
(function (global) {
  'use strict';
  function chapBytes(input) {
    /* RouterOS expands CHAP octal escapes into 8-bit JS characters. Keep those
       bytes intact; UTF-8 re-encoding would change the CHAP challenge bytes. */
    var text = String(input);
    var out = [];
    for (var i = 0; i < text.length; i++) {
      var code = text.charCodeAt(i);
      if (code <= 255) {
        out.push(code);
      } else if (code >= 0xD800 && code <= 0xDBFF && i + 1 < text.length) {
        var low = text.charCodeAt(++i);
        var point = 0x10000 + ((code - 0xD800) << 10) + (low - 0xDC00);
        point -= 0x10000;
        out.push(0xF0 | (point >> 18), 0x80 | ((point >> 12) & 0x3F), 0x80 | ((point >> 6) & 0x3F), 0x80 | (point & 0x3F));
      } else {
        out.push(0xE0 | (code >> 12), 0x80 | ((code >> 6) & 0x3F), 0x80 | (code & 0x3F));
      }
    }
    return out;
  }
  function rotateLeft(value, count) { return (value << count) | (value >>> (32 - count)); }
  function add32(a, b) { return (a + b) >>> 0; }
  function hexWord(value) {
    var s = '';
    for (var i = 0; i < 4; i++) s += ('0' + ((value >>> (i * 8)) & 255).toString(16)).slice(-2);
    return s;
  }
  function hexMD5(input) {
    var bytes = chapBytes(input);
    var bitLength = bytes.length * 8;
    bytes.push(128);
    while ((bytes.length % 64) !== 56) bytes.push(0);
    var low = bitLength >>> 0;
    var high = Math.floor(bitLength / 4294967296) >>> 0;
    for (var j = 0; j < 4; j++) bytes.push((low >>> (j * 8)) & 255);
    for (var k = 0; k < 4; k++) bytes.push((high >>> (k * 8)) & 255);

    var shifts = [7,12,17,22,5,9,14,20,4,11,16,23,6,10,15,21];
    var constants = [];
    for (var c = 0; c < 64; c++) constants[c] = Math.floor(Math.abs(Math.sin(c + 1)) * 4294967296) >>> 0;
    var a0 = 0x67452301, b0 = 0xefcdab89, c0 = 0x98badcfe, d0 = 0x10325476;

    for (var offset = 0; offset < bytes.length; offset += 64) {
      var words = new Array(16);
      for (var w = 0; w < 16; w++) {
        var p = offset + (w * 4);
        words[w] = (bytes[p] | (bytes[p + 1] << 8) | (bytes[p + 2] << 16) | (bytes[p + 3] << 24)) >>> 0;
      }
      var a = a0, b = b0, c1 = c0, d = d0;
      for (var i = 0; i < 64; i++) {
        var f, g, s;
        if (i < 16) { f = (b & c1) | ((~b) & d); g = i; s = shifts[i % 4]; }
        else if (i < 32) { f = (d & b) | ((~d) & c1); g = (5 * i + 1) % 16; s = shifts[4 + (i % 4)]; }
        else if (i < 48) { f = b ^ c1 ^ d; g = (3 * i + 5) % 16; s = shifts[8 + (i % 4)]; }
        else { f = c1 ^ (b | (~d)); g = (7 * i) % 16; s = shifts[12 + (i % 4)]; }
        var sum = add32(add32(add32(a, f >>> 0), constants[i]), words[g]);
        var nextB = add32(b, rotateLeft(sum, s) >>> 0);
        a = d; d = c1; c1 = b; b = nextB;
      }
      a0 = add32(a0, a); b0 = add32(b0, b); c0 = add32(c0, c1); d0 = add32(d0, d);
    }
    return (hexWord(a0) + hexWord(b0) + hexWord(c0) + hexWord(d0)).toLowerCase();
  }
  global.hexMD5 = hexMD5;
}(window));

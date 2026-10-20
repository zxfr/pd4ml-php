package com.pd4ml.bridge;

import java.util.ArrayList;
import java.util.Iterator;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * A minimal JSON reader/writer for the bridge protocol: objects become {@link LinkedHashMap},
 * arrays {@link ArrayList}, integral numbers {@link Long}, other numbers {@link Double}.
 */
final class Json {

    private final String s;
    private int pos;

    private Json(String s) {
        this.s = s;
    }

    static Object parse(String text) {
        Json j = new Json(text);
        j.skipWs();
        Object v = j.value();
        j.skipWs();
        if (j.pos != j.s.length()) {
            throw new IllegalArgumentException("trailing characters at " + j.pos);
        }
        return v;
    }

    private void skipWs() {
        while (pos < s.length() && Character.isWhitespace(s.charAt(pos))) {
            pos++;
        }
    }

    private Object value() {
        if (pos >= s.length()) {
            throw new IllegalArgumentException("unexpected end of JSON");
        }
        char c = s.charAt(pos);
        switch (c) {
            case '{':
                return object();
            case '[':
                return array();
            case '"':
                return string();
            case 't':
                expect("true");
                return Boolean.TRUE;
            case 'f':
                expect("false");
                return Boolean.FALSE;
            case 'n':
                expect("null");
                return null;
            default:
                return number();
        }
    }

    private void expect(String word) {
        if (!s.startsWith(word, pos)) {
            throw new IllegalArgumentException("unexpected token at " + pos);
        }
        pos += word.length();
    }

    private Map<String, Object> object() {
        Map<String, Object> m = new LinkedHashMap<String, Object>();
        pos++;
        skipWs();
        if (s.charAt(pos) == '}') {
            pos++;
            return m;
        }
        while (true) {
            skipWs();
            String key = string();
            skipWs();
            if (s.charAt(pos++) != ':') {
                throw new IllegalArgumentException("':' expected at " + (pos - 1));
            }
            skipWs();
            m.put(key, value());
            skipWs();
            char c = s.charAt(pos++);
            if (c == '}') {
                return m;
            }
            if (c != ',') {
                throw new IllegalArgumentException("',' or '}' expected at " + (pos - 1));
            }
        }
    }

    private List<Object> array() {
        List<Object> a = new ArrayList<Object>();
        pos++;
        skipWs();
        if (s.charAt(pos) == ']') {
            pos++;
            return a;
        }
        while (true) {
            skipWs();
            a.add(value());
            skipWs();
            char c = s.charAt(pos++);
            if (c == ']') {
                return a;
            }
            if (c != ',') {
                throw new IllegalArgumentException("',' or ']' expected at " + (pos - 1));
            }
        }
    }

    private String string() {
        if (s.charAt(pos) != '"') {
            throw new IllegalArgumentException("string expected at " + pos);
        }
        pos++;
        StringBuilder b = new StringBuilder();
        while (true) {
            char c = s.charAt(pos++);
            if (c == '"') {
                return b.toString();
            }
            if (c != '\\') {
                b.append(c);
                continue;
            }
            char e = s.charAt(pos++);
            switch (e) {
                case 'n': b.append('\n'); break;
                case 't': b.append('\t'); break;
                case 'r': b.append('\r'); break;
                case 'b': b.append('\b'); break;
                case 'f': b.append('\f'); break;
                case 'u':
                    b.append((char) Integer.parseInt(s.substring(pos, pos + 4), 16));
                    pos += 4;
                    break;
                default: b.append(e);
            }
        }
    }

    private Object number() {
        int start = pos;
        boolean integral = true;
        while (pos < s.length()) {
            char c = s.charAt(pos);
            if (c == '.' || c == 'e' || c == 'E') {
                integral = false;
            } else if (!(c == '-' || c == '+' || (c >= '0' && c <= '9'))) {
                break;
            }
            pos++;
        }
        String n = s.substring(start, pos);
        if (n.isEmpty()) {
            throw new IllegalArgumentException("unexpected character at " + start);
        }
        return integral ? (Object) Long.valueOf(n) : (Object) Double.valueOf(n);
    }

    // ------------------------------------------------------------------------------- writing

    static String write(Object v) {
        StringBuilder b = new StringBuilder();
        write(b, v);
        return b.toString();
    }

    private static void write(StringBuilder b, Object v) {
        if (v == null) {
            b.append("null");
        } else if (v instanceof String) {
            quote(b, (String) v);
        } else if (v instanceof Boolean) {
            b.append(v.toString());
        } else if (v instanceof Double || v instanceof Float) {
            double d = ((Number) v).doubleValue();
            if (Double.isNaN(d) || Double.isInfinite(d)) {
                b.append("null");
            } else {
                b.append(d);
            }
        } else if (v instanceof Number) {
            b.append(((Number) v).longValue());
        } else if (v instanceof Map) {
            b.append('{');
            Iterator<? extends Map.Entry<?, ?>> it = ((Map<?, ?>) v).entrySet().iterator();
            while (it.hasNext()) {
                Map.Entry<?, ?> e = it.next();
                quote(b, String.valueOf(e.getKey()));
                b.append(':');
                write(b, e.getValue());
                if (it.hasNext()) {
                    b.append(',');
                }
            }
            b.append('}');
        } else if (v instanceof List) {
            b.append('[');
            List<?> l = (List<?>) v;
            for (int i = 0; i < l.size(); i++) {
                if (i > 0) {
                    b.append(',');
                }
                write(b, l.get(i));
            }
            b.append(']');
        } else {
            quote(b, v.toString());
        }
    }

    private static void quote(StringBuilder b, String s) {
        b.append('"');
        for (int i = 0; i < s.length(); i++) {
            char c = s.charAt(i);
            switch (c) {
                case '"': b.append("\\\""); break;
                case '\\': b.append("\\\\"); break;
                case '\n': b.append("\\n"); break;
                case '\r': b.append("\\r"); break;
                case '\t': b.append("\\t"); break;
                default:
                    if (c < 0x20 || c == 0x2028 || c == 0x2029) {
                        b.append(String.format("\\u%04x", (int) c));
                    } else {
                        b.append(c);
                    }
            }
        }
        b.append('"');
    }

}

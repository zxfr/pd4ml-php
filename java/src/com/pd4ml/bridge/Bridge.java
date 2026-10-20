package com.pd4ml.bridge;

import java.io.BufferedReader;
import java.io.ByteArrayInputStream;
import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.IOException;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.io.OutputStreamWriter;
import java.io.PrintStream;
import java.io.PrintWriter;
import java.io.StringWriter;
import java.io.Writer;
import java.lang.reflect.Array;
import java.lang.reflect.Constructor;
import java.lang.reflect.Field;
import java.lang.reflect.InvocationHandler;
import java.lang.reflect.InvocationTargetException;
import java.lang.reflect.Method;
import java.lang.reflect.Modifier;
import java.lang.reflect.Proxy;
import java.net.InetAddress;
import java.net.ServerSocket;
import java.net.Socket;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Base64;
import java.util.Collection;
import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * The Java side of pd4ml-php: gives a PHP process the public PD4ML API (any public Java API, in
 * fact) over a line-based JSON protocol, on stdin/stdout or on a local TCP port.
 *
 * <p>Each request is one JSON object on one line; each response too. Objects stay in the
 * bridge and are referred to by handle. Requests:
 * <pre>
 * {"id":1,"op":"new","class":"com.pd4ml.PD4ML","args":[]}              -&gt; {"id":1,"result":{"$ref":1,"class":"com.pd4ml.PD4ML"}}
 * {"id":2,"op":"call","ref":1,"method":"writePDF","args":[{"$out":0}]} -&gt; {"id":2,"result":null,"out":["JVBERi0..."]}
 * {"id":3,"op":"static","class":"com.pd4ml.PD4ML","method":"getVersion","args":[]}
 * {"id":4,"op":"get","class":"com.pd4ml.PageSize","field":"A4"}       (or "ref" for an instance field)
 * {"id":5,"op":"set","class":"com.pd4ml.PD4ML","field":"disableBatik","value":true}
 * {"id":6,"op":"proxy","interface":"com.pd4ml.ProgressListener","callback":1}
 * {"id":7,"op":"release","refs":[1,2]}
 * </pre>
 * Values: JSON null/boolean/number/string; {@code {"$ref":n}} a bridge object;
 * {@code {"$bytes":"base64"}} a {@code byte[]} (or an {@code InputStream} parameter);
 * {@code {"$out":k}} an {@code OutputStream} whose content comes back as {@code out[k]}. A
 * string argument also converts to a {@code URL}, {@code File} or enum parameter, a JSON object
 * to a {@code Map}, an array to an array or {@code List}. Errors come back as
 * {@code {"id":n,"error":{"class":..., "message":..., "trace":...}}}. A proxy's method calls are
 * sent to the client as {@code {"callback":c,"method":name,"args":[...]}} lines while the
 * request that caused them runs.
 */
public final class Bridge {

    /** Protocol version, reported by the {@code hello} op. */
    static final int PROTOCOL = 1;

    private Bridge() {
    }

    public static void main(String[] args) throws IOException {
        int port = -1;
        for (int i = 0; i < args.length; i++) {
            if ("--port".equals(args[i]) && i + 1 < args.length) {
                port = Integer.parseInt(args[++i]);
            }
        }
        System.setProperty("java.awt.headless", "true");
        if (port >= 0) {
            serve(port);
            return;
        }
        // the protocol owns stdout: anything else printing there goes to stderr
        PrintStream protocol = new PrintStream(System.out, true);
        System.setOut(System.err);
        new Session(System.in, protocol).run();
    }

    private static void serve(int port) throws IOException {
        ServerSocket server = new ServerSocket(port, 50, InetAddress.getLoopbackAddress());
        System.err.println("pd4ml bridge listening on 127.0.0.1:" + server.getLocalPort());
        System.setOut(System.err);
        while (true) {
            final Socket socket = server.accept();
            Thread t = new Thread(new Runnable() {
                @Override
                public void run() {
                    try {
                        new Session(socket.getInputStream(), socket.getOutputStream()).run();
                    } catch (IOException e) {
                        // the client went away
                    } finally {
                        try {
                            socket.close();
                        } catch (IOException ignored) {
                            // closing anyway
                        }
                    }
                }
            }, "pd4ml-bridge-" + socket.getPort());
            t.setDaemon(true);
            t.start();
        }
    }

    // ----------------------------------------------------------------------------------- session

    static final class Session {
        private final BufferedReader in;
        private final Writer out;
        private final Map<Integer, Object> objects = new HashMap<Integer, Object>();
        private int nextId = 1;

        Session(InputStream in, OutputStream out) {
            this.in = new BufferedReader(new InputStreamReader(in, StandardCharsets.UTF_8));
            this.out = new OutputStreamWriter(out, StandardCharsets.UTF_8);
        }

        void run() throws IOException {
            String line;
            while ((line = in.readLine()) != null) {
                if (line.trim().isEmpty()) {
                    continue;
                }
                Map<String, Object> response = new LinkedHashMap<String, Object>();
                Object id = null;
                try {
                    @SuppressWarnings("unchecked")
                    Map<String, Object> req = (Map<String, Object>) Json.parse(line);
                    id = req.get("id");
                    response.put("id", id);
                    if ("close".equals(req.get("op"))) {
                        send(response);
                        return;
                    }
                    List<ByteArrayOutputStream> outs = new ArrayList<ByteArrayOutputStream>();
                    response.put("result", handle(req, outs));
                    if (!outs.isEmpty()) {
                        List<Object> data = new ArrayList<Object>();
                        for (ByteArrayOutputStream o : outs) {
                            data.add(o == null ? null : Base64.getEncoder().encodeToString(o.toByteArray()));
                        }
                        response.put("out", data);
                    }
                } catch (Throwable t) {
                    response.put("id", id);
                    response.put("error", error(t));
                }
                send(response);
            }
        }

        private synchronized void send(Map<String, Object> message) throws IOException {
            out.write(Json.write(message));
            out.write('\n');
            out.flush();
        }

        private Object handle(Map<String, Object> req, List<ByteArrayOutputStream> outs) throws Throwable {
            String op = (String) req.get("op");
            List<?> args = req.get("args") instanceof List ? (List<?>) req.get("args") : new ArrayList<Object>();
            if ("hello".equals(op)) {
                Map<String, Object> info = new LinkedHashMap<String, Object>();
                info.put("protocol", PROTOCOL);
                info.put("java", System.getProperty("java.version"));
                return info;
            }
            if ("new".equals(op)) {
                return encode(construct(findClass((String) req.get("class")), args, outs));
            }
            if ("call".equals(op)) {
                Object target = ref(req.get("ref"));
                return encode(invoke(target.getClass(), target, (String) req.get("method"), args, outs));
            }
            if ("static".equals(op)) {
                return encode(invoke(findClass((String) req.get("class")), null, (String) req.get("method"), args, outs));
            }
            if ("get".equals(op)) {
                Object target = req.get("ref") != null ? ref(req.get("ref")) : null;
                Class<?> cls = target != null ? target.getClass() : findClass((String) req.get("class"));
                Field f = cls.getField((String) req.get("field"));
                return encode(f.get(target));
            }
            if ("set".equals(op)) {
                Object target = req.get("ref") != null ? ref(req.get("ref")) : null;
                Class<?> cls = target != null ? target.getClass() : findClass((String) req.get("class"));
                Field f = cls.getField((String) req.get("field"));
                Conversion c = convert(req.get("value"), f.getType(), outs);
                if (c == null) {
                    throw new IllegalArgumentException("cannot assign " + req.get("value") + " to " + f);
                }
                f.set(target, c.value);
                return null;
            }
            if ("proxy".equals(op)) {
                Class<?> iface = findClass((String) req.get("interface"));
                final Object callback = req.get("callback");
                Object proxy = Proxy.newProxyInstance(iface.getClassLoader(), new Class<?>[] {iface},
                        new InvocationHandler() {
                            @Override
                            public Object invoke(Object p, Method m, Object[] a) throws Throwable {
                                if (m.getDeclaringClass() == Object.class) {
                                    if ("equals".equals(m.getName())) {
                                        return p == a[0];
                                    }
                                    if ("hashCode".equals(m.getName())) {
                                        return System.identityHashCode(p);
                                    }
                                    return "PHP callback " + callback;
                                }
                                Map<String, Object> msg = new LinkedHashMap<String, Object>();
                                msg.put("callback", callback);
                                msg.put("method", m.getName());
                                List<Object> encoded = new ArrayList<Object>();
                                if (a != null) {
                                    for (Object x : a) {
                                        encoded.add(encode(x));
                                    }
                                }
                                msg.put("args", encoded);
                                send(msg);
                                return defaultValue(m.getReturnType());
                            }
                        });
                return encode(proxy);
            }
            if ("release".equals(op)) {
                for (Object r : (List<?>) req.get("refs")) {
                    objects.remove(((Number) r).intValue());
                }
                return null;
            }
            if ("instanceof".equals(op)) {
                return findClass((String) req.get("class")).isInstance(ref(req.get("ref")));
            }
            throw new IllegalArgumentException("unknown op " + op);
        }

        private Object ref(Object id) {
            if (!(id instanceof Number) || !objects.containsKey(((Number) id).intValue())) {
                throw new IllegalArgumentException("no bridge object " + id);
            }
            return objects.get(((Number) id).intValue());
        }

        // ------------------------------------------------------------------------- encoding

        private Object encode(Object v) {
            if (v == null || v instanceof String || v instanceof Boolean || v instanceof Number
                    && (v instanceof Integer || v instanceof Long || v instanceof Short || v instanceof Byte
                        || v instanceof Double || v instanceof Float)) {
                return v;
            }
            if (v instanceof Character) {
                return v.toString();
            }
            if (v instanceof byte[]) {
                Map<String, Object> m = new LinkedHashMap<String, Object>();
                m.put("$bytes", Base64.getEncoder().encodeToString((byte[]) v));
                return m;
            }
            if (v.getClass().isArray()) {
                List<Object> l = new ArrayList<Object>();
                int n = Array.getLength(v);
                for (int i = 0; i < n; i++) {
                    l.add(encode(Array.get(v, i)));
                }
                return l;
            }
            int id = nextId++;
            objects.put(id, v);
            Map<String, Object> m = new LinkedHashMap<String, Object>();
            m.put("$ref", id);
            m.put("class", v.getClass().getName());
            if (v instanceof Enum) {
                m.put("enum", ((Enum<?>) v).name());
            }
            return m;
        }

        // --------------------------------------------------------------------- invocation

        private Object construct(Class<?> cls, List<?> args, List<ByteArrayOutputStream> outs) throws Throwable {
            Constructor<?> best = null;
            Object[] bestArgs = null;
            int bestScore = Integer.MAX_VALUE;
            for (Constructor<?> c : cls.getConstructors()) {
                Object[] converted = new Object[args.size()];
                int score = match(c.getParameterTypes(), args, converted, outs);
                if (score < bestScore) {
                    best = c;
                    bestArgs = converted;
                    bestScore = score;
                }
            }
            if (best == null) {
                throw new NoSuchMethodException("no public constructor of " + cls.getName()
                        + " for " + args.size() + " argument(s) of these types");
            }
            try {
                return best.newInstance(prepareOuts(bestArgs, best.getParameterTypes(), outs));
            } catch (InvocationTargetException e) {
                throw e.getCause();
            }
        }

        private Object invoke(Class<?> cls, Object target, String name, List<?> args,
                              List<ByteArrayOutputStream> outs) throws Throwable {
            Method best = null;
            Object[] bestArgs = null;
            int bestScore = Integer.MAX_VALUE;
            for (Method m : cls.getMethods()) {
                if (!m.getName().equals(name) || (target == null && !Modifier.isStatic(m.getModifiers()))) {
                    continue;
                }
                Object[] converted = new Object[args.size()];
                int score = match(m.getParameterTypes(), args, converted, outs);
                if (score < bestScore) {
                    best = m;
                    bestArgs = converted;
                    bestScore = score;
                }
            }
            if (best == null) {
                throw new NoSuchMethodException("no public " + (target == null ? "static " : "") + "method "
                        + cls.getName() + "." + name + " for " + args.size() + " argument(s) of these types");
            }
            if (!Modifier.isPublic(best.getDeclaringClass().getModifiers())) {
                // a public method of a non-public class (an anonymous/inner implementation)
                best = publicDeclaration(best);
            }
            try {
                return best.invoke(target, prepareOuts(bestArgs, best.getParameterTypes(), outs));
            } catch (InvocationTargetException e) {
                throw e.getCause();
            }
        }

        private static Method publicDeclaration(Method m) {
            for (Class<?> c = m.getDeclaringClass(); c != null; c = c.getSuperclass()) {
                for (Class<?> i : c.getInterfaces()) {
                    try {
                        return i.getMethod(m.getName(), m.getParameterTypes());
                    } catch (NoSuchMethodException ignored) {
                        // not this one
                    }
                }
                if (Modifier.isPublic(c.getModifiers())) {
                    try {
                        return c.getMethod(m.getName(), m.getParameterTypes());
                    } catch (NoSuchMethodException ignored) {
                        // not this one
                    }
                }
            }
            m.setAccessible(true);
            return m;
        }

        /** Output placeholders become fresh streams (registered in {@code outs}) at call time. */
        private Object[] prepareOuts(Object[] args, Class<?>[] types, List<ByteArrayOutputStream> outs) {
            for (int i = 0; i < args.length; i++) {
                if (args[i] instanceof OutPlaceholder) {
                    int k = ((OutPlaceholder) args[i]).index;
                    ByteArrayOutputStream stream = new ByteArrayOutputStream();
                    while (outs.size() <= k) {
                        outs.add(null);
                    }
                    outs.set(k, stream);
                    args[i] = stream;
                }
            }
            return args;
        }

        /** The cost of calling with these parameter types (MAX_VALUE: impossible). */
        private int match(Class<?>[] types, List<?> args, Object[] converted, List<ByteArrayOutputStream> outs) {
            if (types.length != args.size()) {
                return Integer.MAX_VALUE;
            }
            int score = 0;
            for (int i = 0; i < types.length; i++) {
                Conversion c = convert(args.get(i), types[i], outs);
                if (c == null) {
                    return Integer.MAX_VALUE;
                }
                converted[i] = c.value;
                score += c.cost;
            }
            return score;
        }

        // ---------------------------------------------------------------------- conversion

        private static final class Conversion {
            final Object value;
            final int cost;

            Conversion(Object value, int cost) {
                this.value = value;
                this.cost = cost;
            }
        }

        private static final class OutPlaceholder {
            final int index;

            OutPlaceholder(int index) {
                this.index = index;
            }
        }

        /** {@code v} (decoded JSON) as a value of type {@code t}, with a cost; null if impossible. */
        private Conversion convert(Object v, Class<?> t, List<ByteArrayOutputStream> outs) {
            if (v == null) {
                return t.isPrimitive() ? null : new Conversion(null, 1);
            }
            if (v instanceof Map) {
                Map<?, ?> m = (Map<?, ?>) v;
                if (m.containsKey("$ref")) {
                    Object o = objects.get(((Number) m.get("$ref")).intValue());
                    if (o == null) {
                        return null;
                    }
                    if (t.isInstance(o)) {
                        return new Conversion(o, o.getClass() == t ? 0 : 1);
                    }
                    return null;
                }
                if (m.containsKey("$bytes")) {
                    byte[] data = Base64.getDecoder().decode((String) m.get("$bytes"));
                    if (t == byte[].class) {
                        return new Conversion(data, 0);
                    }
                    if (t.isAssignableFrom(ByteArrayInputStream.class)) {
                        return new Conversion(new ByteArrayInputStream(data), 1);
                    }
                    if (t.isAssignableFrom(java.io.BufferedInputStream.class)) {
                        return new Conversion(new java.io.BufferedInputStream(new ByteArrayInputStream(data)), 1);
                    }
                    return null;
                }
                if (m.containsKey("$out")) {
                    return t.isAssignableFrom(ByteArrayOutputStream.class)
                            ? new Conversion(new OutPlaceholder(((Number) m.get("$out")).intValue()), 0) : null;
                }
                if (t.isAssignableFrom(HashMap.class)) {
                    Map<Object, Object> map = new HashMap<Object, Object>();
                    for (Map.Entry<?, ?> e : m.entrySet()) {
                        Object value = e.getValue();
                        map.put(e.getKey(), value instanceof Map || value instanceof List ? value : value == null ? null : String.valueOf(value));
                    }
                    return new Conversion(map, 2);
                }
                return null;
            }
            if (v instanceof Boolean) {
                return t == boolean.class || t == Boolean.class ? new Conversion(v, 0)
                        : t == Object.class ? new Conversion(v, 3) : null;
            }
            if (v instanceof Long) {
                long n = (Long) v;
                if (t == int.class || t == Integer.class) {
                    return n >= Integer.MIN_VALUE && n <= Integer.MAX_VALUE ? new Conversion((int) n, 0) : null;
                }
                if (t == long.class || t == Long.class) {
                    return new Conversion(n, 1);
                }
                if (t == short.class || t == Short.class) {
                    return n >= Short.MIN_VALUE && n <= Short.MAX_VALUE ? new Conversion((short) n, 2) : null;
                }
                if (t == byte.class || t == Byte.class) {
                    return n >= Byte.MIN_VALUE && n <= Byte.MAX_VALUE ? new Conversion((byte) n, 3) : null;
                }
                if (t == float.class || t == Float.class) {
                    return new Conversion((float) n, 3);
                }
                if (t == double.class || t == Double.class) {
                    return new Conversion((double) n, 4);
                }
                if (t == Object.class || t == Number.class) {
                    return new Conversion(n >= Integer.MIN_VALUE && n <= Integer.MAX_VALUE ? (Object) (int) n : (Object) n, 5);
                }
                return null;
            }
            if (v instanceof Double) {
                double d = (Double) v;
                if (t == float.class || t == Float.class) {
                    return new Conversion((float) d, 0);
                }
                if (t == double.class || t == Double.class) {
                    return new Conversion(d, 0);
                }
                if (t == Object.class || t == Number.class) {
                    return new Conversion(d, 5);
                }
                return null;
            }
            if (v instanceof String) {
                String s = (String) v;
                if (t == String.class || t == CharSequence.class) {
                    return new Conversion(s, 0);
                }
                if (t == Object.class) {
                    return new Conversion(s, 2);
                }
                if ((t == char.class || t == Character.class) && s.length() == 1) {
                    return new Conversion(s.charAt(0), 1);
                }
                if (t.isEnum()) {
                    for (Object c : t.getEnumConstants()) {
                        if (((Enum<?>) c).name().equals(s)) {
                            return new Conversion(c, 1);
                        }
                    }
                    return null;
                }
                if (t == URL.class) {
                    try {
                        return new Conversion(new URL(s), 3);
                    } catch (Exception e) {
                        return null;
                    }
                }
                if (t == File.class) {
                    return new Conversion(new File(s), 3);
                }
                return null;
            }
            if (v instanceof List) {
                List<?> l = (List<?>) v;
                if (t.isArray()) {
                    Class<?> ct = t.getComponentType();
                    Object arr = Array.newInstance(ct, l.size());
                    int cost = 1;
                    for (int i = 0; i < l.size(); i++) {
                        Conversion c = convert(l.get(i), ct, outs);
                        if (c == null) {
                            return null;
                        }
                        Array.set(arr, i, c.value);
                        cost += c.cost;
                    }
                    return new Conversion(arr, cost);
                }
                if (t.isAssignableFrom(ArrayList.class) || t == Collection.class) {
                    List<Object> list = new ArrayList<Object>();
                    for (Object x : l) {
                        Conversion c = convert(x, Object.class, outs);
                        list.add(c == null ? x : c.value);
                    }
                    return new Conversion(list, 2);
                }
                return null;
            }
            return null;
        }

        private static Object defaultValue(Class<?> t) {
            if (!t.isPrimitive() || t == void.class) {
                return null;
            }
            if (t == boolean.class) {
                return Boolean.FALSE;
            }
            if (t == char.class) {
                return '\0';
            }
            if (t == long.class) {
                return 0L;
            }
            if (t == float.class) {
                return 0f;
            }
            if (t == double.class) {
                return 0d;
            }
            if (t == short.class) {
                return (short) 0;
            }
            if (t == byte.class) {
                return (byte) 0;
            }
            return 0;
        }

        private static Map<String, Object> error(Throwable t) {
            Map<String, Object> e = new LinkedHashMap<String, Object>();
            e.put("class", t.getClass().getName());
            List<Object> hierarchy = new ArrayList<Object>();
            for (Class<?> c = t.getClass(); c != null && c != Object.class; c = c.getSuperclass()) {
                hierarchy.add(c.getName());
            }
            e.put("hierarchy", hierarchy);
            e.put("message", t.getMessage() == null ? t.toString() : t.getMessage());
            List<Object> chain = new ArrayList<Object>();
            for (Throwable c = t.getCause(); c != null && chain.size() < 8; c = c.getCause()) {
                chain.add(c.getClass().getName() + ": " + c.getMessage());
            }
            e.put("causes", chain);
            StringWriter sw = new StringWriter();
            t.printStackTrace(new PrintWriter(sw));
            String trace = sw.toString();
            e.put("trace", trace.length() > 8000 ? trace.substring(0, 8000) : trace);
            return e;
        }
    }

    // ----------------------------------------------------------------------------- class lookup

    /** A class by name; nested classes also with '.' ({@code com.pd4ml.Constants.LayoutEngine}). */
    static Class<?> findClass(String name) throws ClassNotFoundException {
        ClassLoader loader = Thread.currentThread().getContextClassLoader();
        if (loader == null) {
            loader = Bridge.class.getClassLoader();
        }
        String n = name;
        while (true) {
            try {
                return Class.forName(n, true, loader);
            } catch (ClassNotFoundException e) {
                int dot = n.lastIndexOf('.');
                if (dot < 0) {
                    throw new ClassNotFoundException(name);
                }
                n = n.substring(0, dot) + '$' + n.substring(dot + 1);
            }
        }
    }
}

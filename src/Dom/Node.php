<?php

declare(strict_types=1);

namespace Pd4ml\Dom;

use Pd4ml\JavaObject;

/**
 * A node of the HTML document PD4ML has read (`com.pd4ml.dom.Node`): see {@see \Pd4ml\PD4ML::getDocument()}.
 *
 * Methods keep their Java names; nodes come back as {@see Element}, {@see Text}, {@see Comment},
 * {@see DocumentType} or {@see Document} objects, Java lists as PHP arrays (snapshots: safe to change
 * the document while going through one). Two objects for the same node are {@see equals()}, not
 * necessarily `===`. Java methods not declared here are called through to the Java object.
 *
 * The DOM API needs the v42 layout engine and is part of the PD4ML DMS and UA editions.
 */
abstract class Node implements \Stringable
{
    private const CLASSES = [
        'com.pd4ml.dom.Element' => Element::class,
        'com.pd4ml.dom.Text' => Text::class,
        'com.pd4ml.dom.Comment' => Comment::class,
        'com.pd4ml.dom.Document' => Document::class,
        'com.pd4ml.dom.DocumentType' => DocumentType::class,
    ];

    /** @internal use {@see Node::wrap()} */
    final public function __construct(
        /** The Java object (`com.pd4ml.dom.Node`). */
        public readonly JavaObject $java,
    ) {
    }

    /**
     * The PHP object for a Java `com.pd4ml.dom.Node` (null for null).
     *
     * @internal
     */
    public static function wrap(mixed $node): ?Node
    {
        if ($node === null) {
            return null;
        }
        if (!$node instanceof JavaObject) {
            throw new \UnexpectedValueException('not a Java object: ' . get_debug_type($node));
        }
        $class = self::CLASSES[$node->javaClassName()] ?? null;
        if ($class === null) {
            // a subclass this version does not know: the nearest known class
            foreach (self::CLASSES as $java => $php) {
                if ($node->isInstanceOf($java)) {
                    $class = $php;
                    break;
                }
            }
            if ($class === null) {
                throw new \UnexpectedValueException('not a com.pd4ml.dom node: ' . $node->javaClassName());
            }
        }
        return new $class($node);
    }

    /**
     * PHP nodes for a Java list (or array) of nodes.
     *
     * @internal
     * @return Node[]
     */
    public static function wrapList(mixed $list): array
    {
        return array_map(static fn ($n) => self::wrap($n), self::javaList($list));
    }

    /**
     * The elements of a Java list, set or array as a PHP list (one bridge call).
     *
     * @internal
     */
    public static function javaList(mixed $list): array
    {
        return match (true) {
            $list === null => [],
            is_array($list) => $list,
            $list instanceof JavaObject => (array) $list->toArray(),
            default => throw new \UnexpectedValueException('not a Java list: ' . get_debug_type($list)),
        };
    }

    /** The node name: the tag name for an element (lower case), `#text`, `#comment`, `#document`, `#doctype`. */
    public function getNodeName(): string
    {
        return (string) $this->java->getNodeName();
    }

    public function getParentNode(): ?Node
    {
        return self::wrap($this->java->getParentNode());
    }

    /** The parent if it is an element, else null. */
    public function getParentElement(): ?Element
    {
        return self::wrap($this->java->getParentElement());
    }

    public function getOwnerDocument(): ?Document
    {
        return self::wrap($this->java->getOwnerDocument());
    }

    /**
     * The children (all node types), in document order.
     *
     * @return Node[]
     */
    public function getChildNodes(): array
    {
        return self::wrapList($this->java->getChildNodes());
    }

    public function hasChildNodes(): bool
    {
        return (bool) $this->java->hasChildNodes();
    }

    public function getFirstChild(): ?Node
    {
        return self::wrap($this->java->getFirstChild());
    }

    public function getLastChild(): ?Node
    {
        return self::wrap($this->java->getLastChild());
    }

    public function getNextSibling(): ?Node
    {
        return self::wrap($this->java->getNextSibling());
    }

    public function getPreviousSibling(): ?Node
    {
        return self::wrap($this->java->getPreviousSibling());
    }

    /** The text of the node and everything in it (no comments). */
    public function getTextContent(): ?string
    {
        return $this->java->getTextContent();
    }

    /**
     * Replaces the content with the text (as text, not HTML): an element's children become one text
     * node; a text or comment gets it as its data.
     */
    public function setTextContent(?string $text): void
    {
        $this->java->setTextContent($text);
    }

    /**
     * Appends a node as the last child; a node already in a tree is moved.
     *
     * @return Node the appended node
     */
    public function appendChild(Node $child): Node
    {
        $this->java->appendChild($child->java);
        return $child;
    }

    /**
     * Inserts a node before the child `$ref` (null: at the end).
     *
     * @return Node the inserted node
     */
    public function insertBefore(Node $child, ?Node $ref): Node
    {
        $this->java->insertBefore($child->java, $ref?->java);
        return $child;
    }

    /**
     * Replaces the child `$old` with `$replacement`.
     *
     * @return Node the replaced, now detached, node
     */
    public function replaceChild(Node $replacement, Node $old): Node
    {
        $this->java->replaceChild($replacement->java, $old->java);
        return $old;
    }

    /**
     * Removes a child of this node.
     *
     * @return Node the removed node
     */
    public function removeChild(Node $child): Node
    {
        $this->java->removeChild($child->java);
        return $child;
    }

    /** Removes this node from its parent (nothing happens if it has none). */
    public function remove(): void
    {
        $this->java->remove();
    }

    /** A copy of this node, not in the tree; `$deep`: with its descendants. */
    public function cloneNode(bool $deep = false): Node
    {
        return self::wrap($this->java->cloneNode($deep));
    }

    /** Whether `$other` is this node or inside it. */
    public function contains(?Node $other): bool
    {
        return (bool) $this->java->contains($other?->java);
    }

    /** The HTML of this node (for a document, the whole document, doctype included). */
    public function toHtml(): string
    {
        return (string) $this->java->toHtml();
    }

    /** Whether `$other` is the same node (`===` compares the PHP objects only). */
    public function equals(mixed $other): bool
    {
        return $other instanceof Node && $this->java->equals($other->java);
    }

    /** Java methods not declared here. */
    public function __call(string $method, array $args): mixed
    {
        return $this->java->__call($method, $args);
    }

    public function __toString(): string
    {
        return (string) $this->java;
    }
}

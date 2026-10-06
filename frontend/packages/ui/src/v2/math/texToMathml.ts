import { createElement, type ReactNode } from "react";

/**
 * Bộ chuyển một tập con LaTeX → cây phần tử MathML (React), CHỈ dùng cho bản xem trước v2.
 * Bản thật (FW5/FA5) dùng KaTeX `trust:false` theo tasks.md; giữ nguyên API `<MathText content>`
 * để thay phần render mà không đổi nơi dùng.
 *
 * An toàn: không sinh HTML chuỗi, không `dangerouslySetInnerHTML`; lệnh lạ hiện nguyên văn.
 * Hỗ trợ: \frac \dfrac \sqrt[n]{} ^ _ \left \right, chữ Hy Lạp, toán tử thường gặp ở Toán 6–12,
 * \overline \widehat \vec \text \mathbb, hàm \sin \cos \tan \log \ln \lim.
 */

type Token =
  | { kind: "cmd"; value: string }
  | { kind: "open" }
  | { kind: "close" }
  | { kind: "sup" }
  | { kind: "sub" }
  | { kind: "num"; value: string }
  | { kind: "letter"; value: string }
  | { kind: "char"; value: string }
  | { kind: "lbracket" }
  | { kind: "rbracket" }
  | { kind: "text"; value: string };

function tokenize(input: string): Token[] {
  // "17{,}5" (dấu phẩy thập phân kiểu Việt Nam trong TeX) → một số "17,5".
  const src = input.replace(/(\d)\{,\}(\d)/g, "$1,$2");
  const out: Token[] = [];
  let i = 0;
  while (i < src.length) {
    const c = src[i] as string;
    if (/\s/.test(c)) {
      i++;
      continue;
    }
    if (c === "\\" && /^\\(text|mathrm)\{/.test(src.slice(i))) {
      // Giữ nguyên khoảng trắng trong \text{...} (đơn vị "cm", chữ tiếng Việt).
      const open = src.indexOf("{", i);
      let depth = 1;
      let j = open + 1;
      while (j < src.length && depth > 0) {
        if (src[j] === "{") depth++;
        else if (src[j] === "}") depth--;
        j++;
      }
      out.push({ kind: "text", value: src.slice(open + 1, j - 1) });
      i = j;
      continue;
    }
    if (c === "\\") {
      const rest = src.slice(i + 1);
      const word = /^[a-zA-Z]+/.exec(rest);
      if (word) {
        out.push({ kind: "cmd", value: word[0] });
        i += 1 + word[0].length;
      } else {
        out.push({ kind: "cmd", value: rest[0] ?? "" });
        i += 2;
      }
      continue;
    }
    if (c === "{") out.push({ kind: "open" });
    else if (c === "}") out.push({ kind: "close" });
    else if (c === "^") out.push({ kind: "sup" });
    else if (c === "_") out.push({ kind: "sub" });
    else if (c === "[") out.push({ kind: "lbracket" });
    else if (c === "]") out.push({ kind: "rbracket" });
    else if (/[0-9]/.test(c)) {
      const m = /^[0-9]+(?:[.,][0-9]+)?/.exec(src.slice(i));
      const v = m ? m[0] : c;
      out.push({ kind: "num", value: v });
      i += v.length;
      continue;
    } else if (/[a-zA-Z]/.test(c)) out.push({ kind: "letter", value: c });
    else out.push({ kind: "char", value: c });
    i++;
  }
  return out;
}

const GREEK: Record<string, string> = {
  alpha: "α", beta: "β", gamma: "γ", delta: "δ", epsilon: "ϵ", varepsilon: "ε", theta: "θ", lambda: "λ",
  mu: "μ", pi: "π", rho: "ρ", sigma: "σ", tau: "τ", phi: "ϕ", varphi: "φ", omega: "ω",
  Delta: "Δ", Sigma: "Σ", Omega: "Ω", Pi: "Π", Phi: "Φ",
};

const OPS: Record<string, string> = {
  cdot: "⋅", times: "×", div: "÷", pm: "±", mp: "∓", le: "≤", leq: "≤", ge: "≥", geq: "≥", ne: "≠", neq: "≠",
  lt: "<", gt: ">", approx: "≈", infty: "∞", in: "∈", notin: "∉", subset: "⊂", cup: "∪", cap: "∩", emptyset: "∅",
  Rightarrow: "⇒", Leftrightarrow: "⇔", to: "→", rightarrow: "→", angle: "∠", triangle: "△", perp: "⊥",
  parallel: "∥", sim: "∼", cdots: "⋯", ldots: "…", forall: "∀", exists: "∃", circ: "∘", degree: "°",
  "{": "{", "}": "}", "%": "%", "|": "‖",
};

const LARGE_OPS: Record<string, string> = { sum: "∑", int: "∫", prod: "∏" };
const FUNCS = new Set(["sin", "cos", "tan", "cot", "log", "ln", "lim", "max", "min", "exp"]);
const SPACES: Record<string, string> = { ",": "0.17em", ";": "0.28em", quad: "1em", qquad: "2em", " ": "0.25em" };
const BLACKBOARD: Record<string, string> = { N: "ℕ", Z: "ℤ", Q: "ℚ", R: "ℝ", C: "ℂ" };

/** Con truyền dạng tham số rải (không phải mảng) nên React không cần `key`. */
function el(tag: string, attrs: Record<string, string> | null, ...children: ReactNode[]): ReactNode {
  return createElement(tag, attrs, ...children);
}

function delimiter(t: Token | undefined): string {
  if (!t) return "";
  if (t.kind === "char" || t.kind === "letter" || t.kind === "num") return t.value;
  if (t.kind === "lbracket") return "[";
  if (t.kind === "rbracket") return "]";
  if (t.kind === "cmd") return OPS[t.value] ?? "";
  return "";
}

class Parser {
  private pos = 0;
  constructor(private readonly tokens: Token[]) {}

  private peek(): Token | undefined {
    return this.tokens[this.pos];
  }

  private next(): Token | undefined {
    return this.tokens[this.pos++];
  }

  parseList(stopAtClose = false, stopAtBracket = false): ReactNode[] {
    const nodes: ReactNode[] = [];
    while (this.pos < this.tokens.length) {
      const t = this.peek();
      if (!t) break;
      if (stopAtClose && t.kind === "close") break;
      if (stopAtBracket && t.kind === "rbracket") break;
      if (t.kind === "cmd" && t.value === "right") break;
      nodes.push(this.parseScripted());
    }
    return nodes;
  }

  /** Một nhóm `{...}` hoặc một nguyên tử. */
  private parseArg(): ReactNode {
    const t = this.peek();
    if (t?.kind === "open") {
      this.next();
      const list = this.parseList(true);
      if (this.peek()?.kind === "close") this.next();
      return el("mrow", null, ...list);
    }
    return this.parseAtom();
  }

  private readRawGroup(): string {
    // Dùng cho \mathbb{R}: ghép lại token thành chuỗi.
    if (this.peek()?.kind !== "open") return "";
    this.next();
    let depth = 1;
    let text = "";
    while (this.pos < this.tokens.length && depth > 0) {
      const t = this.next();
      if (!t) break;
      if (t.kind === "open") depth++;
      else if (t.kind === "close") depth--;
      if (depth === 0) break;
      if (t.kind === "num" || t.kind === "letter" || t.kind === "char") text += t.value;
      else if (t.kind === "cmd") text += t.value === " " ? " " : `\\${t.value}`;
    }
    return text;
  }

  private parseScripted(): ReactNode {
    const base = this.parseAtom();
    let sup: ReactNode | null = null;
    let sub: ReactNode | null = null;
    for (let k = 0; k < 2; k++) {
      const t = this.peek();
      if (t?.kind === "sup" && sup === null) {
        this.next();
        sup = this.parseArg();
      } else if (t?.kind === "sub" && sub === null) {
        this.next();
        sub = this.parseArg();
      }
    }
    if (sup !== null && sub !== null) return el("msubsup", null, base, sub, sup);
    if (sup !== null) return el("msup", null, base, sup);
    if (sub !== null) return el("msub", null, base, sub);
    return base;
  }

  private parseAtom(): ReactNode {
    const t = this.next();
    if (!t) return el("mrow", null);
    switch (t.kind) {
      case "num":
        return el("mn", null, t.value);
      case "letter":
        return el("mi", null, t.value);
      case "open": {
        const list = this.parseList(true);
        if (this.peek()?.kind === "close") this.next();
        return el("mrow", null, ...list);
      }
      case "close":
        return el("mrow", null);
      case "text":
        return el("mtext", null, t.value.replace(/^ /, "\u00a0").replace(/ $/, "\u00a0"));
      case "lbracket":
        return el("mo", null, "[");
      case "rbracket":
        return el("mo", null, "]");
      case "sup":
      case "sub":
        return el("mrow", null);
      case "char":
        if (t.value === "'") return el("mo", null, "′");
        return el("mo", null, t.value);
      case "cmd":
        return this.parseCommand(t.value);
    }
  }

  private parseCommand(name: string): ReactNode {
    if (name === "frac" || name === "dfrac" || name === "tfrac") {
      const num = this.parseArg();
      const den = this.parseArg();
      const frac = el("mfrac", null, num, den);
      // \dfrac: phân số cỡ đầy đủ cả khi nằm trong dòng (dễ đọc trên điện thoại).
      return name === "dfrac" ? el("mstyle", { displaystyle: "true", scriptlevel: "0" }, frac) : frac;
    }
    if (name === "sqrt") {
      if (this.peek()?.kind === "lbracket") {
        this.next();
        const index = this.parseList(false, true);
        if (this.peek()?.kind === "rbracket") this.next();
        const radicand = this.parseArg();
        return el("mroot", null, radicand, el("mrow", null, ...index));
      }
      return el("msqrt", null, this.parseArg());
    }
    if (name === "left") {
      const open = delimiter(this.next());
      const inner = this.parseList();
      let close = "";
      const t = this.peek();
      if (t?.kind === "cmd" && t.value === "right") {
        this.next();
        close = delimiter(this.next());
      }
      return el(
        "mrow",
        null,
        open && open !== "." ? el("mo", null, open) : null,
        ...inner,
        close && close !== "." ? el("mo", null, close) : null,
      );
    }
    if (name === "overline") return el("mover", { accent: "true" }, this.parseArg(), el("mo", null, "¯"));
    if (name === "widehat" || name === "hat") return el("mover", { accent: "true" }, this.parseArg(), el("mo", null, "^"));
    if (name === "vec") return el("mover", { accent: "true" }, this.parseArg(), el("mo", null, "→"));
    if (name === "mathbb") {
      const raw = this.readRawGroup();
      return el("mi", { mathvariant: "normal" }, BLACKBOARD[raw] ?? raw);
    }
    if (name in GREEK) return el("mi", null, GREEK[name] as string);
    if (name in LARGE_OPS) return el("mo", { largeop: "true" }, LARGE_OPS[name] as string);
    if (FUNCS.has(name)) return el("mi", { mathvariant: "normal" }, name);
    if (name in SPACES) return el("mspace", { width: SPACES[name] as string });
    if (name in OPS) return el("mo", null, OPS[name] as string);
    // Lệnh không hỗ trợ: hiện nguyên văn để người soạn thấy và sửa.
    return el("mtext", null, `\\${name}`);
  }
}

/** TeX → nội dung bên trong thẻ <math> (mảng node). */
export function texToMathml(tex: string): ReactNode[] {
  const parser = new Parser(tokenize(tex));
  return parser.parseList();
}

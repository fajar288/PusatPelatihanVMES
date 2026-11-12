"use client";

import Link from "next/link";
import { Fragment, useEffect, useRef, useState } from "react";
import { waLink } from "@/lib/utils";

const API = (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1").replace(/\/$/, "");
const STORE = "ppti-chat-v1";
const STATUS = "ppti-chat-status";
const MAX_CHARS = 800;
const MAX_MESSAGES = 24; // dikirim ke server (12 giliran)
const SUGGESTIONS = ["Jadwal pelatihan terdekat", "Berapa biaya pelatihan PLC?", "Bagaimana cara mendaftar?"];

type Msg = { role: "user" | "assistant"; content: string; id?: number | null; feedback?: "up" | "down"; local?: boolean };
type Saved = { conversationId: string; messages: Msg[] };

function load<T>(key: string): T | null {
  try {
    const raw = window.sessionStorage.getItem(key);
    return raw ? (JSON.parse(raw) as T) : null;
  } catch {
    return null;
  }
}

function save(key: string, value: unknown) {
  try {
    window.sessionStorage.setItem(key, JSON.stringify(value));
  } catch {
    /* penyimpanan tidak tersedia (mode privat) — abaikan */
  }
}

function newId(): string {
  return (crypto.randomUUID?.() ?? `${Date.now()}-${Math.random()}`).replace(/[^A-Za-z0-9_-]/g, "");
}

/** Riwayat untuk server: hanya pesan percakapan asli, diawali pesan pengunjung, maks. 24 pesan. */
function history(messages: Msg[]): { role: Msg["role"]; content: string }[] {
  let h = messages.filter((m) => !m.local).map(({ role, content }) => ({ role, content }));
  h = h.slice(-MAX_MESSAGES);
  while (h.length && h[0].role !== "user") h = h.slice(1);
  return h;
}

/** Markdown ringan dari jawaban: **tebal**, daftar "- ", tautan path internal (/kursus/…) & URL. */
function Rich({ text }: { text: string }) {
  const inline = (line: string, key: string) =>
    line.split(/(\*\*[^*]+\*\*|https?:\/\/[^\s)]*[^\s).,;:!?]|(?<![\w/])\/(?:kursus|jadwal|daftar|kontak|portal|register|verifikasi|artikel|tentang|search)[^\s),.]*(?:\.[^\s),.]+)*)/g).map((part, i) => {
      if (!part) return null;
      if (part.startsWith("**") && part.endsWith("**")) return <b key={`${key}-${i}`}>{part.slice(2, -2)}</b>;
      if (/^https?:\/\//.test(part))
        return (
          <a key={`${key}-${i}`} href={part} target="_blank" rel="noopener">
            {part.replace(/^https?:\/\/(www\.)?/, "")}
          </a>
        );
      if (part.startsWith("/")) return <Link key={`${key}-${i}`} href={part}>{part}</Link>;
      return <Fragment key={`${key}-${i}`}>{part}</Fragment>;
    });

  const blocks: React.ReactNode[] = [];
  let list: string[] = [];
  const flush = () => {
    if (list.length) blocks.push(<ul key={`ul-${blocks.length}`}>{list.map((l, i) => <li key={i}>{inline(l, `li-${blocks.length}-${i}`)}</li>)}</ul>);
    list = [];
  };
  text.split("\n").forEach((raw, i) => {
    const line = raw.trim();
    const item = line.match(/^[-*•]\s+(.*)$/);
    if (item) return void list.push(item[1]);
    flush();
    if (line) blocks.push(<p key={`p-${i}`}>{inline(line, `p-${i}`)}</p>);
  });
  flush();
  return <>{blocks}</>;
}

/**
 * Asisten virtual (chatbot informasi publik, Claude via backend /chat). Tidak dirender bila chatbot nonaktif
 * (GET /chat/status). Percakapan disimpan di sessionStorage pengunjung; status aktif di-cache 10 menit.
 */
export default function ChatWidget() {
  const [enabled, setEnabled] = useState(false);
  const [open, setOpen] = useState(false);
  const [conv, setConv] = useState<Saved>({ conversationId: "", messages: [] });
  const [input, setInput] = useState("");
  const [busy, setBusy] = useState(false);
  const listRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    const cached = load<{ enabled: boolean; at: number }>(STATUS);
    const status: Promise<boolean> =
      cached && Date.now() - cached.at < 600_000
        ? Promise.resolve(cached.enabled)
        : fetch(`${API}/chat/status`, { headers: { Accept: "application/json" } })
            .then((r) => (r.ok ? r.json() : { enabled: false }))
            .then((j: { enabled?: boolean }) => {
              save(STATUS, { enabled: !!j.enabled, at: Date.now() });
              return !!j.enabled;
            })
            .catch(() => false);
    let alive = true;
    void status.then((on) => {
      if (!alive || !on) return;
      setConv(load<Saved>(STORE) ?? { conversationId: newId(), messages: [] });
      setEnabled(true);
    });
    return () => {
      alive = false;
    };
  }, []);

  useEffect(() => {
    if (conv.conversationId) save(STORE, conv);
    listRef.current?.scrollTo({ top: listRef.current.scrollHeight, behavior: "smooth" });
  }, [conv]);

  useEffect(() => {
    if (!open) return;
    inputRef.current?.focus();
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open]);

  if (!enabled) return null;

  const send = async (text: string) => {
    const content = text.trim().slice(0, MAX_CHARS);
    if (!content || busy) return;
    const messages: Msg[] = [...conv.messages, { role: "user", content }];
    setConv({ ...conv, messages });
    setInput("");
    setBusy(true);
    let reply: Msg;
    try {
      const r = await fetch(`${API}/chat`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ conversationId: conv.conversationId, messages: history(messages) }),
      });
      const j = await r.json().catch(() => null);
      if (r.ok && j?.data?.reply) {
        reply = { role: "assistant", content: j.data.reply, id: j.data.id };
      } else if (r.status === 429) {
        reply = { role: "assistant", local: true, content: j?.data?.reply ?? "Pertanyaan Anda terlalu cepat atau batas hari ini sudah tercapai. Silakan coba lagi nanti, atau hubungi admin lewat WhatsApp." };
      } else {
        reply = { role: "assistant", local: true, content: j?.message ?? "Maaf, asisten sedang tidak dapat menjawab. Silakan hubungi admin lewat WhatsApp." };
      }
    } catch {
      reply = { role: "assistant", local: true, content: "Koneksi terputus. Periksa internet Anda lalu coba lagi, atau hubungi admin lewat WhatsApp." };
    }
    // Pesan gagal (local) tidak dikirim ulang sebagai riwayat; pertanyaan terakhir ikut dibuang agar giliran tetap berselang-seling.
    setConv((c) => ({ ...c, messages: reply.local ? [...c.messages.slice(0, -1).concat({ ...c.messages[c.messages.length - 1], local: true }), reply] : [...c.messages, reply] }));
    setBusy(false);
  };

  const rate = async (index: number, value: "up" | "down") => {
    const m = conv.messages[index];
    if (!m?.id || m.feedback) return;
    setConv((c) => ({ ...c, messages: c.messages.map((x, i) => (i === index ? { ...x, feedback: value } : x)) }));
    fetch(`${API}/chat/feedback`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ id: m.id, conversationId: conv.conversationId, value }),
    }).catch(() => {});
  };

  const reset = () => setConv({ conversationId: newId(), messages: [] });

  return (
    <>
      <button type="button" className={"chatf" + (open ? " on" : "")} onClick={() => setOpen((o) => !o)} aria-expanded={open} aria-controls="ppti-chat" aria-label={open ? "Tutup asisten virtual" : "Buka asisten virtual"}>
        <svg viewBox="0 0 24 24" aria-hidden="true">
          {open ? <path d="M6 6l12 12M18 6L6 18" /> : <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H10l-4.5 4v-4h0A1.5 1.5 0 0 1 4 14.5zM8.5 9.5h.01M12 9.5h.01M15.5 9.5h.01" />}
        </svg>
        <span>{open ? "Tutup" : "Tanya Asisten"}</span>
      </button>

      {open && (
        <section id="ppti-chat" className="chatp" role="dialog" aria-label="Asisten virtual PPTI">
          <header>
            <div>
              <b>Asisten Virtual PPTI</b>
              <small>Dibantu AI · jawaban bisa keliru, cek detail di halaman program</small>
            </div>
            {conv.messages.length > 0 && (
              <button type="button" className="chatr" onClick={reset} title="Mulai percakapan baru">
                Baru
              </button>
            )}
          </header>

          <div className="chatl" ref={listRef} aria-live="polite">
            <div className="chatm bot">
              <p>Halo! Saya asisten virtual PPTI - VMES. Tanyakan program pelatihan, jadwal, biaya, atau cara mendaftar.</p>
              {conv.messages.length === 0 && (
                <div className="chats">
                  {SUGGESTIONS.map((s) => (
                    <button type="button" key={s} onClick={() => send(s)}>
                      {s}
                    </button>
                  ))}
                </div>
              )}
            </div>
            {conv.messages.map((m, i) => (
              <div key={i} className={"chatm " + (m.role === "user" ? "me" : "bot")}>
                {m.role === "user" ? <p>{m.content}</p> : <Rich text={m.content} />}
                {m.role === "assistant" && m.id && (
                  <div className="chatfb" aria-label="Apakah jawaban ini membantu?">
                    {m.feedback ? (
                      <small>Terima kasih atas penilaiannya.</small>
                    ) : (
                      <>
                        <button type="button" onClick={() => rate(i, "up")} aria-label="Membantu">
                          👍
                        </button>
                        <button type="button" onClick={() => rate(i, "down")} aria-label="Tidak membantu">
                          👎
                        </button>
                      </>
                    )}
                  </div>
                )}
              </div>
            ))}
            {busy && (
              <div className="chatm bot typing" aria-label="Asisten sedang mengetik">
                <i />
                <i />
                <i />
              </div>
            )}
          </div>

          <form
            className="chati"
            onSubmit={(e) => {
              e.preventDefault();
              void send(input);
            }}
          >
            <textarea
              ref={inputRef}
              rows={1}
              value={input}
              maxLength={MAX_CHARS}
              placeholder="Tulis pertanyaan…"
              aria-label="Pertanyaan untuk asisten"
              onChange={(e) => setInput(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter" && !e.shiftKey) {
                  e.preventDefault();
                  void send(input);
                }
              }}
            />
            <button type="submit" disabled={busy || !input.trim()} aria-label="Kirim">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 12l16-8-6 16-2.5-6.5z" />
              </svg>
            </button>
          </form>
          <p className="chatw">
            Butuh bantuan langsung?{" "}
            <a href={waLink()} target="_blank" rel="noopener">
              Chat admin di WhatsApp
            </a>
          </p>
        </section>
      )}
    </>
  );
}

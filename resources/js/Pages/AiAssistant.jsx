import React, { useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Send, Sparkles } from 'lucide-react';

const SUGGESTIONS = [
    'Give me a report for the night team this week',
    'Who tracked the most hours in the last 7 days?',
    'Which clients took the most time this week?',
    'Anyone with unusually low hours recently?',
];

export default function AiAssistant({ auth, aiEnabled = false, questions = SUGGESTIONS }) {
    const [messages, setMessages] = useState([]);
    const [input, setInput] = useState('');
    const [loading, setLoading] = useState(false);
    const bottomRef = useRef(null);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages, loading]);

    const send = async (text) => {
        const content = (text ?? input).trim();
        if (!content || loading) return;

        const next = [...messages, { role: 'user', content }];
        setMessages(next);
        setInput('');
        setLoading(true);

        try {
            // Send the last 10 turns so follow-up questions keep context.
            const res = await axios.post(route('ai.assistant.ask'), { messages: next.slice(-10) });
            setMessages([...next, { role: 'assistant', content: res.data.reply }]);
        } catch (err) {
            const msg = err.response?.data?.message || 'Something went wrong — try again.';
            setMessages([...next, { role: 'assistant', content: `⚠️ ${msg}` }]);
        } finally {
            setLoading(false);
        }
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="text-xl font-semibold text-slate-900">AI Assistant</h2>}>
            <Head title="AI Assistant" />

            <div className="min-h-screen bg-slate-950">
                <div className="mx-auto flex h-[calc(100vh-8rem)] max-w-3xl flex-col px-4 py-6 sm:px-6">
                    <div className="flex-1 space-y-4 overflow-y-auto rounded-t-lg border border-b-0 border-slate-800 bg-slate-900 p-5">
                        {messages.length === 0 && (
                            <div className="flex h-full flex-col items-center justify-center gap-4 text-center">
                                <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-amber-500 shadow-lg shadow-orange-500/30">
                                    <Sparkles className="h-6 w-6 text-white" />
                                </span>
                                <div>
                                    <p className="font-semibold text-white">Ask about your team&apos;s work</p>
                                    <p className="mt-1 text-sm text-slate-400">I can see the last 14 days of tracked hours, clients, and shifts.</p>
                                </div>
                                <div className="flex flex-wrap justify-center gap-2">
                                    {(questions || []).map((s) => (
                                        <button
                                            key={s}
                                            type="button"
                                            onClick={() => send(s)}
                                            disabled={!aiEnabled}
                                            className="rounded-full border border-slate-700 px-3 py-1.5 text-xs text-slate-300 transition hover:border-orange-500/50 hover:bg-orange-500/10 hover:text-orange-300"
                                        >
                                            {s}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        {messages.map((m, i) => (
                            <div key={i} className={m.role === 'user' ? 'flex justify-end' : 'flex justify-start'}>
                                <div
                                    className={[
                                        'max-w-[85%] whitespace-pre-wrap rounded-2xl px-4 py-2.5 text-sm leading-6',
                                        m.role === 'user'
                                            ? 'bg-gradient-to-r from-orange-500 to-amber-500 text-white'
                                            : 'border border-slate-800 bg-slate-950/60 text-slate-200',
                                    ].join(' ')}
                                >
                                    {m.content}
                                </div>
                            </div>
                        ))}

                        {loading && (
                            <div className="flex justify-start">
                                <div className="rounded-2xl border border-slate-800 bg-slate-950/60 px-4 py-3 text-sm text-slate-400">
                                    <span className="inline-flex gap-1">
                                        <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-orange-400" style={{ animationDelay: '0ms' }} />
                                        <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-orange-400" style={{ animationDelay: '150ms' }} />
                                        <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-orange-400" style={{ animationDelay: '300ms' }} />
                                    </span>
                                </div>
                            </div>
                        )}
                        <div ref={bottomRef} />
                    </div>

                    <div className="rounded-b-lg border border-slate-800 bg-slate-900 p-3">
                        {!aiEnabled ? (
                            <p className="px-2 py-1 text-sm text-amber-300">
                                AI is not configured yet — a Super Admin can add the Anthropic key under Developer → AI.
                            </p>
                        ) : (
                            <form
                                onSubmit={(e) => { e.preventDefault(); send(); }}
                                className="flex items-end gap-2"
                            >
                                <textarea
                                    value={input}
                                    onChange={(e) => setInput(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
                                    }}
                                    rows={2}
                                    placeholder="Ask about hours, people, clients, shifts…"
                                    className="flex-1 resize-none rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-200 placeholder-slate-500 focus:border-orange-500 focus:ring-orange-500"
                                />
                                <button
                                    type="submit"
                                    disabled={loading || !input.trim()}
                                    className="inline-flex h-10 items-center gap-1.5 rounded-lg bg-gradient-to-r from-orange-500 to-amber-500 px-4 text-sm font-semibold text-white shadow-lg shadow-orange-500/25 transition hover:from-orange-600 hover:to-amber-600 disabled:opacity-50"
                                >
                                    <Send className="h-4 w-4" />
                                    Send
                                </button>
                            </form>
                        )}
                        <p className="mt-1.5 px-2 text-[11px] text-slate-500">
                            Answers come from the last 14 days of work-diary data. Conversations are not stored.
                        </p>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

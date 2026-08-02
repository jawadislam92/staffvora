import React, { useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Send, Sparkles } from 'lucide-react';
import { Card, Button, Field, Textarea } from '@/Components/ui';

const cx = (...a) => a.filter(Boolean).join(' ');

const SUGGESTIONS = [
    'Give me a report for the night team this week',
    'Who tracked the most hours in the last 7 days?',
    'Which clients took the most time this week?',
    'Anyone with unusually low hours recently?',
];

// --- Preview-only sample data (offline). Never used when preview=false. ---
const DEV_SAMPLE = {
    user: {
        id: 1,
        name: 'Jawad',
        email: 'jawad@staffvora.com',
        is_super_admin: true,
        permissions: [],
    },
    aiEnabled: true,
    questions: SUGGESTIONS,
    messages: [
        { role: 'user', content: 'Who tracked the most hours in the last 7 days?' },
        {
            role: 'assistant',
            content:
                'Over the last 7 days the top trackers were:\n\n1. Aqsa Khan — 41h 20m\n2. Bilal Ahmed — 38h 05m\n3. Sana Riaz — 36h 44m\n\nAqsa put in the longest single day (9h 10m on Tuesday).',
        },
        { role: 'user', content: 'How does that compare to the week before?' },
        {
            role: 'assistant',
            content:
                'The top three are roughly flat week-over-week. Aqsa is up about 2h, Bilal is down about 3h, and Sana is nearly unchanged. Overall team hours rose ~4% versus the prior 7 days.',
        },
    ],
};

export default function AiAssistant({
    auth,
    aiEnabled = false,
    questions = SUGGESTIONS,
    preview = false,
}) {
    // In preview mode, run fully offline from DEV_SAMPLE (mock super-admin user).
    const user = preview ? DEV_SAMPLE.user : auth?.user;
    const enabled = preview ? DEV_SAMPLE.aiEnabled : aiEnabled;
    const prompts = preview ? DEV_SAMPLE.questions : questions;

    const [messages, setMessages] = useState(preview ? DEV_SAMPLE.messages : []);
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

        if (preview) return; // Offline in preview — no network.

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
        <AuthenticatedLayout user={user} header="AI Assistant">
            <Head title="AI Assistant" />

            <div className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 space-y-6">
                <Card padded={false} className="flex h-[calc(100vh-12rem)] flex-col overflow-hidden">
                    {/* Conversation */}
                    <div className="flex-1 space-y-4 overflow-y-auto p-5">
                        {messages.length === 0 && (
                            <div className="flex h-full flex-col items-center justify-center gap-4 text-center">
                                <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-accent/10 text-accent">
                                    <Sparkles className="h-6 w-6" />
                                </span>
                                <div>
                                    <p className="font-semibold text-body">Ask about your team&apos;s work</p>
                                    <p className="mt-1 text-sm text-muted">
                                        I can see the last 14 days of tracked hours, clients, and shifts.
                                    </p>
                                </div>
                                <div className="flex flex-wrap justify-center gap-2">
                                    {(prompts || []).map((s) => (
                                        <button
                                            key={s}
                                            type="button"
                                            onClick={() => send(s)}
                                            disabled={!enabled}
                                            className="rounded-full border border-line px-3 py-1.5 text-xs text-muted transition hover:border-accent hover:bg-accent/10 hover:text-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app disabled:opacity-50 disabled:cursor-not-allowed"
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
                                    className={cx(
                                        'max-w-[85%] whitespace-pre-wrap rounded-2xl px-4 py-2.5 text-sm leading-6',
                                        m.role === 'user'
                                            ? 'bg-accent text-white'
                                            : 'border border-line bg-surface-2 text-body'
                                    )}
                                >
                                    {m.content}
                                </div>
                            </div>
                        ))}

                        {loading && (
                            <div className="flex justify-start">
                                <div className="rounded-2xl border border-line bg-surface-2 px-4 py-3 text-sm text-muted">
                                    <span className="inline-flex gap-1">
                                        <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-accent" style={{ animationDelay: '0ms' }} />
                                        <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-accent" style={{ animationDelay: '150ms' }} />
                                        <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-accent" style={{ animationDelay: '300ms' }} />
                                    </span>
                                </div>
                            </div>
                        )}
                        <div ref={bottomRef} />
                    </div>

                    {/* Composer */}
                    <div className="border-t border-line bg-surface p-3">
                        {!enabled ? (
                            <p className="rounded-md bg-warning-soft px-3 py-2 text-sm text-warning">
                                AI is not configured yet — a Super Admin can add the Anthropic key under Developer → AI.
                            </p>
                        ) : (
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    send();
                                }}
                                className="flex items-end gap-2"
                            >
                                <Field className="flex-1" label="Message" htmlFor="ai-message">
                                    <Textarea
                                        id="ai-message"
                                        value={input}
                                        onChange={(e) => setInput(e.target.value)}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter' && !e.shiftKey) {
                                                e.preventDefault();
                                                send();
                                            }
                                        }}
                                        rows={2}
                                        placeholder="Ask about hours, people, clients, shifts…"
                                        className="min-h-0"
                                    />
                                </Field>
                                <Button
                                    type="submit"
                                    loading={loading}
                                    disabled={loading || !input.trim()}
                                    icon={<Send className="h-4 w-4" />}
                                >
                                    Send
                                </Button>
                            </form>
                        )}
                        <p className="mt-1.5 px-1 text-[11px] text-subtle">
                            Answers come from the last 14 days of work-diary data. Conversations are not stored.
                        </p>
                    </div>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}

import { useCallback, useEffect, useRef, useState } from "react";
import { usePage } from "@inertiajs/react";
import { LogIn, LogOut, Coffee, Play } from "lucide-react";
import { Button } from "@/Components/ui";

const cx = (...a) => a.filter(Boolean).join(" ");

// Map the shared `auth.lastActionToday` string into one of our three UI states.
function statusFromLastAction(lastAction) {
    switch (lastAction) {
        case "break_start":
            return "on_break";
        case "clock_out":
            return "out";
        case "clock_in":
        case "break_end":
            return "working";
        default:
            return "out";
    }
}

// The action to POST given the current UI state. Break, from a "working" pill,
// is a separate handler so we can send break_start explicitly.
function nextActionFor(status) {
    if (status === "out") return "clock_in";
    if (status === "on_break") return "break_end";
    return "clock_out"; // working -> clock out (break handled separately)
}

function pad(n) {
    return String(n).padStart(2, "0");
}

function formatElapsed(totalSeconds) {
    const s = Math.max(0, Math.floor(totalSeconds));
    return `${pad(Math.floor(s / 3600))}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`;
}

/**
 * ClockControl — the state-aware Clock In / Out control that lives in the top bar.
 *
 * States: "out" (primary Clock in button) · "working" (live timer pill + Clock out
 * + Break) · "on_break" (paused pill + Resume). All network calls degrade
 * gracefully — a 401/failure just drops the timer, never crashes the shell.
 *
 * @param {object}  props.user
 * @param {string}  [props.className]
 * @param {"out"|"working"|"on_break"} [props.initialStatus] Force a state (preview).
 * @param {number}  [props.initialElapsedSeconds] Seed the timer without a backend.
 */
export default function ClockControl({
    user,
    className,
    initialStatus,
    initialElapsedSeconds,
    ...rest
}) {
    const lastActionToday = usePage().props?.auth?.lastActionToday ?? null;

    const [status, setStatus] = useState(
        () => initialStatus ?? statusFromLastAction(lastActionToday)
    );
    // elapsedRef holds the running second count; `elapsed` mirrors it for render.
    const [elapsed, setElapsed] = useState(initialElapsedSeconds ?? 0);
    const [busy, setBusy] = useState(false);

    const startEpochRef = useRef(null); // ms when the current session began
    const tickRef = useRef(null);

    // --- live ticking ------------------------------------------------------
    const stopTicking = useCallback(() => {
        if (tickRef.current) {
            clearInterval(tickRef.current);
            tickRef.current = null;
        }
    }, []);

    const startTicking = useCallback(() => {
        stopTicking();
        tickRef.current = setInterval(() => {
            if (startEpochRef.current != null) {
                setElapsed(Math.floor((Date.now() - startEpochRef.current) / 1000));
            } else {
                setElapsed((e) => e + 1);
            }
        }, 1000);
    }, [stopTicking]);

    // --- resolve the current session's start time from the backend ---------
    // Only when we're in a live state and weren't given a seed elapsed value.
    const resolveTiming = useCallback(async () => {
        try {
            const { data } = await window.axios.get("/time-entries/today");
            // Accept a few likely shapes without hard-coupling to one API contract.
            const entries =
                data?.entries || data?.time_entries || data?.data || (Array.isArray(data) ? data : []);
            let startTs = null;

            if (Array.isArray(entries) && entries.length) {
                // Walk from the most recent entry back to the clock_in/break_end
                // that opened the currently-running segment.
                for (let i = entries.length - 1; i >= 0; i -= 1) {
                    const e = entries[i];
                    const type = e?.action_type || e?.type;
                    const ts = e?.created_at || e?.timestamp || e?.action_at || e?.time;
                    if (!ts) continue;
                    if (type === "clock_in" || type === "break_end") {
                        startTs = ts;
                        break;
                    }
                }
                // Fallback: first clock_in of the day.
                if (!startTs) {
                    const firstIn = entries.find(
                        (e) => (e?.action_type || e?.type) === "clock_in"
                    );
                    startTs = firstIn?.created_at || firstIn?.timestamp || null;
                }
            }

            const startMs = startTs ? new Date(startTs).getTime() : NaN;
            if (!Number.isNaN(startMs)) {
                startEpochRef.current = startMs;
                setElapsed(Math.floor((Date.now() - startMs) / 1000));
            }
        } catch {
            // 401 / network / shape mismatch — show status without a timer.
            startEpochRef.current = null;
        }
    }, []);

    // Kick off timing whenever we enter/leave the "working" state.
    useEffect(() => {
        if (status === "working") {
            if (initialElapsedSeconds != null && startEpochRef.current == null) {
                // Seeded preview: anchor an epoch so the tick stays accurate.
                startEpochRef.current = Date.now() - initialElapsedSeconds * 1000;
                setElapsed(initialElapsedSeconds);
                startTicking();
            } else if (startEpochRef.current == null) {
                resolveTiming().finally(startTicking);
            } else {
                startTicking();
            }
        } else {
            // out / on_break — freeze the timer.
            stopTicking();
            if (status === "out") {
                startEpochRef.current = null;
                setElapsed(0);
            }
        }
        return stopTicking;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [status]);

    // --- actions -----------------------------------------------------------
    const post = useCallback(
        async (action_type, optimisticStatus) => {
            if (busy) return;
            setBusy(true);
            const prev = status;
            setStatus(optimisticStatus);
            try {
                await window.axios.post("/time-entries", { action_type });
                // Re-resolve timing for the new segment.
                if (optimisticStatus === "working") {
                    startEpochRef.current = null;
                    await resolveTiming();
                }
            } catch (err) {
                // 422 = blocked transition (e.g. double-start). Roll back quietly.
                if (err?.response?.status === 422) {
                    setStatus(prev);
                } else {
                    // Other failures: keep optimistic state but don't crash.
                }
            } finally {
                setBusy(false);
            }
        },
        [busy, status, resolveTiming]
    );

    const onPrimary = () => {
        if (status === "out") post("clock_in", "working");
        else if (status === "on_break") post("break_end", "working");
        else post("clock_out", "out");
    };
    const onBreak = () => post("break_start", "on_break");

    // --- render ------------------------------------------------------------
    if (status === "out") {
        return (
            <Button
                size="sm"
                variant="primary"
                loading={busy}
                onClick={onPrimary}
                icon={<LogIn className="h-4 w-4" />}
                className={cx("h-9 whitespace-nowrap", className)}
                {...rest}
            >
                <span className="hidden sm:inline">Clock in</span>
                <span className="sm:hidden">In</span>
            </Button>
        );
    }

    const onBreakState = status === "on_break";

    return (
        <div
            role="group"
            aria-label={onBreakState ? "You are on break" : "You are clocked in"}
            className={cx(
                "flex h-9 items-center gap-2 rounded-lg border border-line bg-surface pl-2.5 pr-1",
                className
            )}
            {...rest}
        >
            <span className="flex items-center gap-2">
                <span
                    aria-hidden="true"
                    className={cx(
                        "h-2.5 w-2.5 shrink-0 rounded-full motion-safe:animate-pulse",
                        onBreakState ? "bg-warning" : "bg-success"
                    )}
                />
                {onBreakState ? (
                    <span className="hidden text-xs font-semibold uppercase tracking-wide text-warning sm:inline">
                        On break
                    </span>
                ) : (
                    <span className="hidden font-mono text-sm font-semibold tabular-nums text-body md:inline">
                        {formatElapsed(elapsed)}
                    </span>
                )}
            </span>

            <span aria-hidden="true" className="hidden h-5 w-px bg-line sm:block" />

            {onBreakState ? (
                <Button
                    size="sm"
                    variant="primary"
                    loading={busy}
                    onClick={onPrimary}
                    icon={<Play className="h-4 w-4" />}
                    className="h-7 whitespace-nowrap"
                >
                    Resume
                </Button>
            ) : (
                <>
                    <Button
                        size="sm"
                        variant="ghost"
                        disabled={busy}
                        onClick={onBreak}
                        icon={<Coffee className="h-4 w-4" />}
                        className="hidden h-7 whitespace-nowrap text-muted hover:text-warning sm:inline-flex"
                    >
                        Break
                    </Button>
                    <Button
                        size="sm"
                        variant="primary"
                        loading={busy}
                        onClick={onPrimary}
                        icon={<LogOut className="h-4 w-4" />}
                        className="h-7 whitespace-nowrap"
                    >
                        <span className="hidden sm:inline">Clock out</span>
                        <span className="sm:hidden">Out</span>
                    </Button>
                </>
            )}
        </div>
    );
}

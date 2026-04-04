"use client";

import { useState, useEffect, useCallback, useMemo } from "react";
import { createClient } from "@/lib/supabase";
import { useCurrentUser } from "@/hooks/use-current-user";
import type { ProjectTask, TaskStatus, TaskPriority, TaskAssignmentStatus } from "@/types/database";
import { IconPlus, IconX, IconTrash, IconEdit, IconCheck } from "@/components/ui/icons";
import { appConfirm } from "@/components/ui/confirm-dialog";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import { DateInput } from "@/components/ui/date-input";

// --- Styles ---

const statusStyles: Record<TaskStatus, { bg: string; color: string; label: string }> = {
  todo: { bg: "var(--color-muted)", color: "var(--color-muted-foreground)", label: "Offen" },
  in_progress: { bg: "var(--color-info-light)", color: "var(--color-info)", label: "In Arbeit" },
  done: { bg: "var(--color-success-light)", color: "var(--color-success)", label: "Erledigt" },
};

const priorityStyles: Record<TaskPriority, { bg: string; color: string; label: string }> = {
  high: { bg: "var(--color-destructive-light)", color: "var(--color-destructive)", label: "Hoch" },
  medium: { bg: "var(--color-warning-light)", color: "var(--color-warning)", label: "Mittel" },
  low: { bg: "var(--color-success-light)", color: "var(--color-success)", label: "Niedrig" },
};

const assigneeTypeBadge: Record<string, { bg: string; color: string; label: string }> = {
  member: { bg: "#dbeafe", color: "#1d4ed8", label: "Intern" },
  team: { bg: "#dcfce7", color: "#16a34a", label: "Crew" },
  contact: { bg: "#fef3c7", color: "#b45309", label: "Extern" },
};

const statusOrder: TaskStatus[] = ["todo", "in_progress", "done"];

// --- Assignee Helpers ---

interface AssigneeOption {
  type: "member" | "team" | "contact";
  id: string;
  name: string;
  detail: string;
}

function parseAssigneeValue(val: string): { type: "member" | "team" | "contact" | null; id: string | null } {
  if (!val) return { type: null, id: null };
  const [type, ...rest] = val.split(":");
  return { type: type as "member" | "team" | "contact", id: rest.join(":") };
}

function toAssigneeColumns(val: string) {
  const { type, id } = parseAssigneeValue(val);
  return {
    assigned_to: type === "member" ? id : null,
    assigned_to_team_id: type === "team" ? id : null,
    assigned_to_contact_id: type === "contact" ? id : null,
  };
}

function taskToAssigneeValue(task: ProjectTask): string {
  if (task.assigned_to) return `member:${task.assigned_to}`;
  if (task.assigned_to_team_id) return `team:${task.assigned_to_team_id}`;
  if (task.assigned_to_contact_id) return `contact:${task.assigned_to_contact_id}`;
  return "";
}

function getAssigneeName(task: ProjectTask): { name: string; type: "member" | "team" | "contact" } | null {
  if (task.assigned_to && task.profiles?.name) {
    return { name: task.profiles.name, type: "member" };
  }
  if (task.assigned_to_team_id && task.project_team?.name) {
    return { name: task.project_team.name, type: "team" };
  }
  if (task.assigned_to_contact_id && task.project_contacts?.name) {
    return { name: task.project_contacts.name, type: "contact" };
  }
  return null;
}

// --- Assignee Select Component ---

function AssigneeSelect({
  value,
  onChange,
  options,
  style,
  className,
}: {
  value: string;
  onChange: (val: string) => void;
  options: AssigneeOption[];
  style?: React.CSSProperties;
  className?: string;
}) {
  const members = options.filter((o) => o.type === "member");
  const team = options.filter((o) => o.type === "team");
  const contacts = options.filter((o) => o.type === "contact");

  return (
    <select value={value} onChange={(e) => onChange(e.target.value)} className={className} style={style}>
      <option value="">— Niemand —</option>
      {members.length > 0 && (
        <optgroup label="Intern (Projekt-Mitglieder)">
          {members.map((m) => (
            <option key={`member:${m.id}`} value={`member:${m.id}`}>
              {m.name}{m.detail ? ` (${m.detail})` : ""}
            </option>
          ))}
        </optgroup>
      )}
      {team.length > 0 && (
        <optgroup label="Team / Crew">
          {team.map((m) => (
            <option key={`team:${m.id}`} value={`team:${m.id}`}>
              {m.name}{m.detail ? ` (${m.detail})` : ""}
            </option>
          ))}
        </optgroup>
      )}
      {contacts.length > 0 && (
        <optgroup label="Externe Kontakte">
          {contacts.map((m) => (
            <option key={`contact:${m.id}`} value={`contact:${m.id}`}>
              {m.name}{m.detail ? ` (${m.detail})` : ""}
            </option>
          ))}
        </optgroup>
      )}
    </select>
  );
}

// --- Main Component ---

interface TabTasksProps {
  projectId: string;
}

export function TabTasks({ projectId }: TabTasksProps) {
  const supabase = createClient();
  const currentUser = useCurrentUser();
  const [tasks, setTasks] = useState<ProjectTask[]>([]);
  const [assigneeOptions, setAssigneeOptions] = useState<AssigneeOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState<"all" | "mine" | TaskStatus>("all");

  // Create form
  const [showForm, setShowForm] = useState(false);
  const [formTitle, setFormTitle] = useState("");
  const [formDescription, setFormDescription] = useState("");
  const [formPriority, setFormPriority] = useState<TaskPriority>("medium");
  const [formAssignee, setFormAssignee] = useState("");
  const [formDueDate, setFormDueDate] = useState("");
  const [saving, setSaving] = useState(false);

  // Edit mode
  const [editingId, setEditingId] = useState<string | null>(null);
  const [editTitle, setEditTitle] = useState("");
  const [editDescription, setEditDescription] = useState("");
  const [editPriority, setEditPriority] = useState<TaskPriority>("medium");
  const [editAssignee, setEditAssignee] = useState("");
  const [editDueDate, setEditDueDate] = useState("");
  const [editStatus, setEditStatus] = useState<TaskStatus>("todo");

  const loadData = useCallback(async () => {
    const [tasksRes, membersRes, teamRes, contactsRes] = await Promise.all([
      supabase
        .from("project_tasks")
        .select(`
          *,
          profiles:assigned_to(name),
          project_team:assigned_to_team_id(name, role, department),
          project_contacts:assigned_to_contact_id(name, role, company)
        `)
        .eq("project_id", projectId)
        .order("sort_order")
        .order("created_at"),
      supabase
        .from("project_members")
        .select("profile_id, role, profiles(name)")
        .eq("project_id", projectId),
      supabase
        .from("project_team")
        .select("id, name, role, department")
        .eq("project_id", projectId),
      supabase
        .from("project_contacts")
        .select("id, name, role, company")
        .eq("project_id", projectId),
    ]);

    if (tasksRes.data) setTasks(tasksRes.data as ProjectTask[]);

    // Build assignee options from all three sources
    const opts: AssigneeOption[] = [];
    if (membersRes.data) {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      for (const m of membersRes.data as any[]) {
        const name = Array.isArray(m.profiles) ? m.profiles[0]?.name : m.profiles?.name;
        const roleLabel = m.role === "owner" ? "Eigentümer" : m.role === "editor" ? "Bearbeiter" : "Betrachter";
        opts.push({ type: "member", id: m.profile_id, name: name || "?", detail: roleLabel });
      }
    }
    if (teamRes.data) {
      for (const t of teamRes.data) {
        const parts = [t.role, t.department].filter(Boolean);
        opts.push({ type: "team", id: t.id, name: t.name, detail: parts.join(", ") });
      }
    }
    if (contactsRes.data) {
      for (const c of contactsRes.data) {
        const parts = [c.role, c.company].filter(Boolean);
        opts.push({ type: "contact", id: c.id, name: c.name, detail: parts.join(", ") });
      }
    }
    setAssigneeOptions(opts);
    setLoading(false);
  }, [supabase, projectId]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  useRealtimeTable({
    table: "project_tasks",
    filter: { column: "project_id", value: projectId },
    onDataChange: loadData,
  });

  // Stats
  const counts = useMemo(() => {
    const c = { todo: 0, in_progress: 0, done: 0, all: tasks.length, mine: 0 };
    for (const t of tasks) {
      c[t.status]++;
      if (t.assigned_to === currentUser?.id) c.mine++;
    }
    return c;
  }, [tasks, currentUser?.id]);

  const filtered = useMemo(() => {
    if (statusFilter === "all") return tasks;
    if (statusFilter === "mine") return tasks.filter((t) => t.assigned_to === currentUser?.id);
    return tasks.filter((t) => t.status === statusFilter);
  }, [tasks, statusFilter, currentUser?.id]);

  // --- CRUD ---

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    if (!formTitle.trim()) return;
    setSaving(true);

    const maxOrder = tasks.length > 0 ? Math.max(...tasks.map((t) => t.sort_order)) : -1;
    const { data: { user } } = await supabase.auth.getUser();
    const assigneeCols = toAssigneeColumns(formAssignee);
    const isProfileAssignment = !!assigneeCols.assigned_to;

    const { data: inserted } = await supabase.from("project_tasks").insert({
      project_id: projectId,
      title: formTitle.trim(),
      description: formDescription.trim() || null,
      priority: formPriority,
      ...assigneeCols,
      assignment_status: isProfileAssignment ? "pending" : "accepted",
      assigned_at: formAssignee ? new Date().toISOString() : null,
      due_date: formDueDate || null,
      created_by: user?.id || null,
      sort_order: maxOrder + 1,
    }).select("id").single();

    // Notification für Profile-Zuweisung (nur wenn nicht sich selbst zugewiesen)
    if (inserted && isProfileAssignment && assigneeCols.assigned_to !== user?.id) {
      await supabase.from("task_notifications").insert({
        task_id: inserted.id,
        profile_id: assigneeCols.assigned_to!,
        type: "assigned",
      });
    }
    // Sich selbst zugewiesen → sofort accepted
    if (inserted && isProfileAssignment && assigneeCols.assigned_to === user?.id) {
      await supabase.from("project_tasks").update({ assignment_status: "accepted" }).eq("id", inserted.id);
    }

    setFormTitle("");
    setFormDescription("");
    setFormPriority("medium");
    setFormAssignee("");
    setFormDueDate("");
    setShowForm(false);
    setSaving(false);
    loadData();
  }

  async function handleToggleDone(task: ProjectTask) {
    const newStatus: TaskStatus = task.status === "done" ? "todo" : "done";
    setTasks((prev) => prev.map((t) => (t.id === task.id ? { ...t, status: newStatus } : t)));
    await supabase.from("project_tasks").update({ status: newStatus }).eq("id", task.id);
  }

  async function handleStatusChange(taskId: string, newStatus: TaskStatus) {
    setTasks((prev) => prev.map((t) => (t.id === taskId ? { ...t, status: newStatus } : t)));
    await supabase.from("project_tasks").update({ status: newStatus }).eq("id", taskId);
  }

  async function handleDelete(taskId: string) {
    if (!(await appConfirm("Aufgabe wirklich löschen?", { variant: "danger", confirmLabel: "Löschen" }))) return;
    await supabase.from("project_tasks").delete().eq("id", taskId);
    loadData();
  }

  function startEdit(task: ProjectTask) {
    setEditingId(task.id);
    setEditTitle(task.title);
    setEditDescription(task.description || "");
    setEditPriority(task.priority);
    setEditAssignee(taskToAssigneeValue(task));
    setEditDueDate(task.due_date || "");
    setEditStatus(task.status);
  }

  async function handleSaveEdit() {
    if (!editingId || !editTitle.trim()) return;

    const oldTask = tasks.find((t) => t.id === editingId);
    const assigneeCols = toAssigneeColumns(editAssignee);
    const isProfileAssignment = !!assigneeCols.assigned_to;
    const assigneeChanged = oldTask && taskToAssigneeValue(oldTask) !== editAssignee;

    await supabase
      .from("project_tasks")
      .update({
        title: editTitle.trim(),
        description: editDescription.trim() || null,
        priority: editPriority,
        ...assigneeCols,
        assignment_status: !editAssignee
          ? "pending"
          : isProfileAssignment && assigneeCols.assigned_to !== currentUser?.id
            ? "pending"
            : "accepted",
        assigned_at: assigneeChanged ? new Date().toISOString() : oldTask?.assigned_at || null,
        due_date: editDueDate || null,
        status: editStatus,
      })
      .eq("id", editingId);

    // Notification bei neuer Profile-Zuweisung
    if (assigneeChanged && isProfileAssignment && assigneeCols.assigned_to !== currentUser?.id) {
      await supabase.from("task_notifications").insert({
        task_id: editingId,
        profile_id: assigneeCols.assigned_to!,
        type: "assigned",
      });
    }

    setEditingId(null);
    loadData();
  }

  // --- Accept / Decline ---

  async function handleAcceptTask(taskId: string) {
    await supabase.from("project_tasks").update({ assignment_status: "accepted" }).eq("id", taskId);
    // Notification als gelesen markieren
    if (currentUser?.id) {
      await supabase
        .from("task_notifications")
        .update({ is_read: true })
        .eq("task_id", taskId)
        .eq("profile_id", currentUser.id);
    }
    loadData();
  }

  async function handleDeclineTask(taskId: string) {
    await supabase.from("project_tasks").update({ assignment_status: "declined" }).eq("id", taskId);
    if (currentUser?.id) {
      await supabase
        .from("task_notifications")
        .update({ is_read: true })
        .eq("task_id", taskId)
        .eq("profile_id", currentUser.id);
    }
    loadData();
  }

  // --- Helpers ---

  function isOverdue(dueDate: string | null, status: TaskStatus): boolean {
    if (!dueDate || status === "done") return false;
    return new Date(dueDate) < new Date(new Date().toDateString());
  }

  const inputStyle = {
    border: "1px solid var(--color-border)",
    background: "var(--color-background)",
  };

  if (loading) {
    return (
      <div className="space-y-3">
        {[1, 2, 3, 4].map((i) => (
          <div key={i} className="h-16 skeleton rounded-lg" />
        ))}
      </div>
    );
  }

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-lg font-bold">Aufgaben</h2>
          <p className="text-xs mt-0.5" style={{ color: "var(--color-muted-foreground)" }}>
            {counts.todo} offen &middot; {counts.in_progress} in Arbeit &middot; {counts.done} erledigt
          </p>
        </div>
        <button
          onClick={() => setShowForm(!showForm)}
          className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium text-white"
          style={{ background: "var(--color-primary)" }}
          onMouseEnter={(e) => (e.currentTarget.style.background = "var(--color-primary-hover)")}
          onMouseLeave={(e) => (e.currentTarget.style.background = "var(--color-primary)")}
        >
          <IconPlus size={14} /> Neue Aufgabe
        </button>
      </div>

      {/* Create Form */}
      {showForm && (
        <div
          className="p-5 rounded-xl animate-slideDown"
          style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)", boxShadow: "var(--shadow-sm)" }}
        >
          <div className="flex items-center justify-between mb-4">
            <h3 className="font-semibold text-sm">Neue Aufgabe</h3>
            <button onClick={() => setShowForm(false)} style={{ color: "var(--color-muted-foreground)" }}>
              <IconX size={18} />
            </button>
          </div>
          <form onSubmit={handleCreate} className="space-y-3">
            <div>
              <input
                type="text"
                value={formTitle}
                onChange={(e) => setFormTitle(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={inputStyle}
                placeholder="Was muss gemacht werden?"
                required
                autoFocus
              />
            </div>
            <div>
              <input
                type="text"
                value={formDescription}
                onChange={(e) => setFormDescription(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={inputStyle}
                placeholder="Beschreibung (optional)"
              />
            </div>
            <div className="grid grid-cols-3 gap-3">
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>
                  Priorität
                </label>
                <select
                  value={formPriority}
                  onChange={(e) => setFormPriority(e.target.value as TaskPriority)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={inputStyle}
                >
                  <option value="low">Niedrig</option>
                  <option value="medium">Mittel</option>
                  <option value="high">Hoch</option>
                </select>
              </div>
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>
                  Zuständig
                </label>
                <AssigneeSelect
                  value={formAssignee}
                  onChange={setFormAssignee}
                  options={assigneeOptions}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={inputStyle}
                />
              </div>
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>
                  Fällig bis
                </label>
                <DateInput
                  value={formDueDate}
                  onChange={setFormDueDate}
                />
              </div>
            </div>
            <div className="flex gap-2 pt-1">
              <button
                type="submit"
                disabled={saving}
                className="px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
                style={{ background: "var(--color-primary)" }}
              >
                {saving ? "Wird erstellt..." : "Erstellen"}
              </button>
              <button
                type="button"
                onClick={() => setShowForm(false)}
                className="px-4 py-2 rounded-lg text-sm"
                style={{ border: "1px solid var(--color-border)" }}
              >
                Abbrechen
              </button>
            </div>
          </form>
        </div>
      )}

      {/* Filter Pills */}
      <div className="flex gap-2 flex-wrap">
        {(["all", "mine", ...statusOrder] as const).map((s) => {
          const label = s === "all" ? "Alle" : s === "mine" ? "Meine" : statusStyles[s].label;
          const count = counts[s];
          const active = statusFilter === s;
          return (
            <button
              key={s}
              onClick={() => setStatusFilter(s)}
              className="px-3 py-1.5 rounded-full text-xs font-medium transition-all"
              style={{
                background: active ? "var(--color-primary)" : "var(--color-surface)",
                color: active ? "#fff" : "var(--color-muted-foreground)",
                border: active ? "none" : "1px solid var(--color-border-light)",
              }}
            >
              {label} ({count})
            </button>
          );
        })}
      </div>

      {/* Task List */}
      {filtered.length === 0 ? (
        <div
          className="text-center py-12 rounded-xl"
          style={{ border: "2px dashed var(--color-border)", color: "var(--color-muted-foreground)" }}
        >
          <p className="text-sm">
            {statusFilter === "all"
              ? "Noch keine Aufgaben angelegt"
              : statusFilter === "mine"
                ? "Keine Aufgaben an dich zugewiesen"
                : `Keine Aufgaben mit Status „${statusStyles[statusFilter].label}"`}
          </p>
        </div>
      ) : (
        <div className="space-y-2">
          {filtered.map((task) => {
            const isEditing = editingId === task.id;
            const overdue = isOverdue(task.due_date, task.status);
            const assignee = getAssigneeName(task);
            const isPendingForMe =
              task.assigned_to === currentUser?.id && task.assignment_status === "pending";

            if (isEditing) {
              return (
                <div
                  key={task.id}
                  className="p-4 rounded-xl space-y-3"
                  style={{
                    background: "var(--color-surface)",
                    border: "2px solid var(--color-primary)",
                    boxShadow: "var(--shadow-sm)",
                  }}
                >
                  <input
                    type="text"
                    value={editTitle}
                    onChange={(e) => setEditTitle(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm font-medium"
                    style={inputStyle}
                    autoFocus
                  />
                  <input
                    type="text"
                    value={editDescription}
                    onChange={(e) => setEditDescription(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm"
                    style={inputStyle}
                    placeholder="Beschreibung"
                  />
                  <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <select
                      value={editStatus}
                      onChange={(e) => setEditStatus(e.target.value as TaskStatus)}
                      className="px-3 py-2 rounded-lg text-sm"
                      style={inputStyle}
                    >
                      {statusOrder.map((s) => (
                        <option key={s} value={s}>{statusStyles[s].label}</option>
                      ))}
                    </select>
                    <select
                      value={editPriority}
                      onChange={(e) => setEditPriority(e.target.value as TaskPriority)}
                      className="px-3 py-2 rounded-lg text-sm"
                      style={inputStyle}
                    >
                      <option value="low">Niedrig</option>
                      <option value="medium">Mittel</option>
                      <option value="high">Hoch</option>
                    </select>
                    <AssigneeSelect
                      value={editAssignee}
                      onChange={setEditAssignee}
                      options={assigneeOptions}
                      className="px-3 py-2 rounded-lg text-sm"
                      style={inputStyle}
                    />
                    <DateInput
                      value={editDueDate}
                      onChange={setEditDueDate}
                    />
                  </div>
                  <div className="flex gap-2">
                    <button
                      onClick={handleSaveEdit}
                      className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-white"
                      style={{ background: "var(--color-primary)" }}
                    >
                      <IconCheck size={12} /> Speichern
                    </button>
                    <button
                      onClick={() => setEditingId(null)}
                      className="px-3 py-1.5 rounded-lg text-xs"
                      style={{ border: "1px solid var(--color-border)" }}
                    >
                      Abbrechen
                    </button>
                  </div>
                </div>
              );
            }

            return (
              <div
                key={task.id}
                className="flex items-start gap-3 p-4 rounded-xl transition-colors group"
                style={{
                  background: isPendingForMe ? "var(--color-warning-light)" : "var(--color-surface)",
                  border: isPendingForMe
                    ? "1px solid var(--color-warning)"
                    : "1px solid var(--color-border-light)",
                  opacity: task.status === "done" ? 0.7 : 1,
                }}
                onMouseEnter={(e) => {
                  if (!isPendingForMe) e.currentTarget.style.borderColor = "var(--color-border)";
                }}
                onMouseLeave={(e) => {
                  if (!isPendingForMe) e.currentTarget.style.borderColor = "var(--color-border-light)";
                }}
              >
                {/* Checkbox */}
                <button
                  onClick={() => handleToggleDone(task)}
                  className="mt-0.5 w-5 h-5 rounded flex-shrink-0 flex items-center justify-center transition-colors"
                  style={{
                    border: task.status === "done" ? "none" : "2px solid var(--color-border)",
                    background: task.status === "done" ? "var(--color-success)" : "transparent",
                    color: task.status === "done" ? "#fff" : "transparent",
                  }}
                  title={task.status === "done" ? "Als offen markieren" : "Als erledigt markieren"}
                >
                  {task.status === "done" && <IconCheck size={12} />}
                </button>

                {/* Content */}
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span
                      className="text-sm font-medium"
                      style={{ textDecoration: task.status === "done" ? "line-through" : "none" }}
                    >
                      {task.title}
                    </span>
                    {task.status === "in_progress" && (
                      <span
                        className="text-xs px-1.5 py-0.5 rounded-full font-medium"
                        style={{ background: statusStyles.in_progress.bg, color: statusStyles.in_progress.color }}
                      >
                        {statusStyles.in_progress.label}
                      </span>
                    )}
                    <span
                      className="text-xs px-1.5 py-0.5 rounded-full font-medium"
                      style={{ background: priorityStyles[task.priority].bg, color: priorityStyles[task.priority].color }}
                    >
                      {priorityStyles[task.priority].label}
                    </span>
                  </div>
                  {task.description && (
                    <p className="text-xs mt-0.5" style={{ color: "var(--color-muted-foreground)" }}>
                      {task.description}
                    </p>
                  )}
                  {/* Meta Row */}
                  <div className="flex items-center gap-3 mt-1.5 flex-wrap">
                    {assignee && (
                      <span className="flex items-center gap-1 text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                        <span
                          className="w-4 h-4 rounded-full flex items-center justify-center text-[9px] font-bold text-white"
                          style={{ background: assigneeTypeBadge[assignee.type].color }}
                        >
                          {assignee.name.charAt(0).toUpperCase()}
                        </span>
                        {assignee.name}
                        <span
                          className="text-[10px] px-1 py-0.5 rounded font-medium ml-0.5"
                          style={{
                            background: assigneeTypeBadge[assignee.type].bg,
                            color: assigneeTypeBadge[assignee.type].color,
                          }}
                        >
                          {assigneeTypeBadge[assignee.type].label}
                        </span>
                        {/* Assignment Status Badge */}
                        {task.assignment_status === "pending" && task.assigned_to && (
                          <span
                            className="text-[10px] px-1 py-0.5 rounded font-medium"
                            style={{ background: "var(--color-warning-light)", color: "var(--color-warning)" }}
                          >
                            Ausstehend
                          </span>
                        )}
                        {task.assignment_status === "declined" && task.assigned_to && (
                          <span
                            className="text-[10px] px-1 py-0.5 rounded font-medium"
                            style={{ background: "var(--color-destructive-light)", color: "var(--color-destructive)" }}
                          >
                            Abgelehnt
                          </span>
                        )}
                      </span>
                    )}
                    {task.due_date && (
                      <span
                        className="text-xs"
                        style={{
                          color: overdue ? "var(--color-destructive)" : "var(--color-muted-foreground)",
                          fontWeight: overdue ? 600 : 400,
                        }}
                      >
                        📅{" "}
                        {new Date(task.due_date).toLocaleDateString("de-DE", {
                          day: "numeric",
                          month: "short",
                        })}
                        {overdue && " (überfällig)"}
                      </span>
                    )}
                    {task.status !== "done" && (
                      <button
                        onClick={() =>
                          handleStatusChange(task.id, task.status === "todo" ? "in_progress" : "done")
                        }
                        className="text-xs px-1.5 py-0.5 rounded-full font-medium opacity-0 group-hover:opacity-100 transition-opacity"
                        style={{
                          background: task.status === "todo" ? statusStyles.in_progress.bg : statusStyles.done.bg,
                          color: task.status === "todo" ? statusStyles.in_progress.color : statusStyles.done.color,
                        }}
                      >
                        → {task.status === "todo" ? "In Arbeit" : "Erledigt"}
                      </button>
                    )}
                  </div>

                  {/* Accept/Decline for pending tasks assigned to me */}
                  {isPendingForMe && (
                    <div className="flex gap-2 mt-2">
                      <button
                        onClick={() => handleAcceptTask(task.id)}
                        className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium"
                        style={{ background: "var(--color-success)", color: "white" }}
                      >
                        <IconCheck size={12} /> Annehmen
                      </button>
                      <button
                        onClick={() => handleDeclineTask(task.id)}
                        className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium"
                        style={{ border: "1px solid var(--color-border)", color: "var(--color-muted-foreground)" }}
                      >
                        <IconX size={12} /> Ablehnen
                      </button>
                    </div>
                  )}
                </div>

                {/* Actions */}
                <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                  <button
                    onClick={() => startEdit(task)}
                    className="p-1 rounded transition-colors"
                    style={{ color: "var(--color-muted-foreground)" }}
                    title="Bearbeiten"
                  >
                    <IconEdit size={14} />
                  </button>
                  <button
                    onClick={() => handleDelete(task.id)}
                    className="p-1 rounded transition-colors"
                    style={{ color: "var(--color-destructive)" }}
                    title="Löschen"
                  >
                    <IconTrash size={14} />
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}

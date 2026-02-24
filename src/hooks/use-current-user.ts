"use client";

import { useState, useEffect } from "react";
import { createClient } from "@/lib/supabase";

export interface CurrentUser {
  id: string;
  email: string;
  name: string;
  roleName: string; // 'admin' | 'manager' | 'member'
  isActive: boolean;
}

export function useCurrentUser(): CurrentUser | null {
  const [user, setUser] = useState<CurrentUser | null>(null);

  useEffect(() => {
    const supabase = createClient();

    async function fetchUser() {
      const {
        data: { user: authUser },
      } = await supabase.auth.getUser();
      if (!authUser) return;

      const { data: profile } = await supabase
        .from("profiles")
        .select("name, email, is_active, roles(name)")
        .eq("id", authUser.id)
        .single();

      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const rolesRaw = profile?.roles as any;
      const roles: { name: string } | null = Array.isArray(rolesRaw)
        ? rolesRaw[0] ?? null
        : rolesRaw ?? null;

      setUser({
        id: authUser.id,
        email: profile?.email || authUser.email || "",
        name:
          profile?.name ||
          authUser.user_metadata?.name ||
          authUser.email?.split("@")[0] ||
          "User",
        roleName: roles?.name || "member",
        isActive: profile?.is_active ?? false,
      });
    }

    fetchUser();
  }, []);

  return user;
}

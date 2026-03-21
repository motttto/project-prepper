"use client";

import { createContext, useContext, useState, useCallback } from "react";
import type { UserPermissions } from "@/types/database";

interface ImpersonatedUser {
  profileId: string;
  name: string;
  roleName: string;
  permissions: UserPermissions;
}

interface ImpersonateContextType {
  impersonating: ImpersonatedUser | null;
  startImpersonating: (user: ImpersonatedUser) => void;
  stopImpersonating: () => void;
}

const ImpersonateContext = createContext<ImpersonateContextType>({
  impersonating: null,
  startImpersonating: () => {},
  stopImpersonating: () => {},
});

export function ImpersonateProvider({ children }: { children: React.ReactNode }) {
  const [impersonating, setImpersonating] = useState<ImpersonatedUser | null>(null);

  const startImpersonating = useCallback((user: ImpersonatedUser) => {
    setImpersonating(user);
  }, []);

  const stopImpersonating = useCallback(() => {
    setImpersonating(null);
  }, []);

  return (
    <ImpersonateContext.Provider value={{ impersonating, startImpersonating, stopImpersonating }}>
      {children}
    </ImpersonateContext.Provider>
  );
}

export function useImpersonate() {
  return useContext(ImpersonateContext);
}

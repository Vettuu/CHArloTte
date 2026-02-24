import { AIInput } from "@/components/ui/ai-input"

export function AIInputDemo() {
  return (
    <div className="space-y-8 min-w-[400px]">
      <div>
        <AIInput 
          onSubmit={(value) => console.log('Submitted:', value)} 
        />
      </div>
    </div>
  )
}
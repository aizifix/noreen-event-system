"use client";

import type React from "react";
import { useState, useEffect, useRef, useCallback, useMemo } from "react";
import { Check } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";

interface Step {
  id: string;
  title: string;
  description: string;
  component: React.ReactNode;
}

interface MultiStepWizardProps {
  steps: Step[];
  onComplete: () => void;
}

export function MultiStepWizard({ steps, onComplete }: MultiStepWizardProps) {
  const [currentStepIndex, setCurrentStepIndex] = useState(0);
  const [completedSteps, setCompletedSteps] = useState<string[]>([]);
  const scrollContainerRef = useRef<HTMLDivElement>(null);

  const currentStep = steps[currentStepIndex];
  const isFirstStep = currentStepIndex === 0;
  const isLastStep = currentStepIndex === steps.length - 1;

  // Auto-scroll to center current step - memoized to prevent unnecessary re-renders
  useEffect(() => {
    if (scrollContainerRef.current) {
      const container = scrollContainerRef.current;
      const stepWidth = 154; // Match Figma spacing
      const containerWidth = container.clientWidth;
      const targetScroll =
        currentStepIndex * stepWidth - containerWidth / 2 + stepWidth / 2;

      container.scrollTo({
        left: Math.max(0, targetScroll),
        behavior: "smooth",
      });
    }
  }, [currentStepIndex]);

  const handleNext = useCallback(() => {
    if (isLastStep) {
      onComplete();
      return;
    }

    // Mark current step as completed
    if (!completedSteps.includes(currentStep.id)) {
      setCompletedSteps((prev) => [...prev, currentStep.id]);
    }

    setCurrentStepIndex((prev) => prev + 1);
  }, [isLastStep, onComplete, completedSteps, currentStep.id]);

  const handlePrevious = useCallback(() => {
    if (!isFirstStep) {
      setCurrentStepIndex((prev) => prev - 1);
    }
  }, [isFirstStep]);

  const handleStepClick = useCallback(
    (index: number) => {
      // Only allow clicking on completed steps or the next available step
      if (
        completedSteps.includes(steps[index].id) ||
        index === 0 ||
        (index <= completedSteps.length && index === currentStepIndex + 1)
      ) {
        setCurrentStepIndex(index);
      }
    },
    [completedSteps, steps, currentStepIndex]
  );

  const getStepStatus = useCallback(
    (index: number) => {
      const isCompleted =
        completedSteps.includes(steps[index].id) || index < currentStepIndex;
      const isCurrent = index === currentStepIndex;

      if (isCompleted) return "completed";
      if (isCurrent) return "current";
      return "pending";
    },
    [completedSteps, steps, currentStepIndex]
  );

  const isClickable = useCallback(
    (index: number) => {
      return (
        completedSteps.includes(steps[index].id) ||
        index === 0 ||
        (index <= completedSteps.length && index === currentStepIndex + 1)
      );
    },
    [completedSteps, steps, currentStepIndex]
  );

  // Memoize the step rendering to prevent unnecessary re-renders
  const renderedSteps = useMemo(() => {
    return steps.map((step, index) => {
      const status = getStepStatus(index);
      const clickable = isClickable(index);

      return (
        <div key={step.id} className="flex flex-col min-w-[154px]">
          {/* Step Circle and Line Row */}
          <div className="flex items-center">
            {/* Step Circle */}
            <div className="relative">
              {status === "current" ? (
                // Current step: border circle with inner filled circle
                <div
                  className="relative cursor-pointer transition-all duration-300 hover:scale-105"
                  onClick={() => clickable && handleStepClick(index)}
                >
                  <div className="w-11 h-11 rounded-full border-4 border-[#028A75] bg-transparent" />
                  <div className="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-6 h-6 rounded-full bg-[#028A75] flex items-center justify-center">
                    <span className="text-white text-sm font-semibold">
                      {index + 1}
                    </span>
                  </div>
                </div>
              ) : (
                // Completed and pending: solid circles
                <div
                  className={cn(
                    "w-11 h-11 rounded-full flex items-center justify-center transition-all duration-300 cursor-pointer text-sm font-semibold",
                    {
                      "bg-[#028A75] text-white": status === "completed",
                      "bg-[#DADADA] text-gray-600": status === "pending",
                      "hover:scale-105": clickable,
                      "cursor-not-allowed": !clickable,
                    }
                  )}
                  onClick={() => clickable && handleStepClick(index)}
                >
                  {status === "completed" ? (
                    <Check className="h-6 w-6 text-white" strokeWidth={2} />
                  ) : (
                    index + 1
                  )}
                </div>
              )}
            </div>

            {/* Connecting Line */}
            {index < steps.length - 1 && (
              <div className="flex items-center ml-4">
                <div className="relative h-1 w-[72px]">
                  {/* Background Line */}
                  <div className="absolute inset-0 bg-[#DADADA] rounded-sm" />
                  {/* Progress Line */}
                  <div
                    className={cn(
                      "absolute inset-0 bg-[#028A75] rounded-sm transition-all duration-500",
                      {
                        "w-full": index < currentStepIndex,
                        "w-0": index >= currentStepIndex,
                      }
                    )}
                  />
                </div>
              </div>
            )}
          </div>

          {/* Step Labels - Left aligned */}
          <div className="mt-4 text-left">
            <div className="text-xs font-medium text-[#C7C7C7] uppercase tracking-wide">
              STEP {index + 1}
            </div>
            <div
              className={cn("text-sm font-medium mt-1 transition-colors", {
                "text-[#87878A]":
                  status === "completed" ||
                  status === "current" ||
                  status === "pending",
              })}
            >
              {step.title}
            </div>
            <div
              className={cn("text-sm mt-1 font-medium transition-colors", {
                "text-[#028A75]":
                  status === "completed" || status === "current",
                "text-[#DADADA]": status === "pending",
              })}
            >
              {status === "completed"
                ? "Completed"
                : status === "current"
                  ? "In Progress"
                  : "Pending"}
            </div>
          </div>
        </div>
      );
    });
  }, [steps, getStepStatus, isClickable, handleStepClick, currentStepIndex]);

  return (
    <div className="space-y-6">
      {/* Compact Stepper with Scroll */}
      <div className="relative">
        {/* Scrollable Container */}
        <div
          ref={scrollContainerRef}
          className="overflow-x-auto scrollbar-hide py-4 px-8"
          style={{ scrollbarWidth: "none", msOverflowStyle: "none" }}
        >
          <div className="flex items-center min-w-max space-x-1">
            {renderedSteps}
          </div>
        </div>
      </div>

      {/* Step Content Card */}
      <Card className="shadow-lg">
        <CardHeader className="pb-4">
          <div className="flex items-center justify-between">
            <div>
              <CardTitle className="text-xl">{currentStep.title}</CardTitle>
              <p className="text-sm text-muted-foreground mt-1">
                {currentStep.description}
              </p>
            </div>
            <div className="text-sm text-muted-foreground">
              Step {currentStepIndex + 1} of {steps.length}
            </div>
          </div>
        </CardHeader>
        <CardContent className="pt-0">{currentStep.component}</CardContent>
        <CardFooter className="flex justify-between pt-6 border-t">
          <Button
            variant="outline"
            onClick={handlePrevious}
            disabled={isFirstStep}
            className="px-6"
          >
            Previous
          </Button>
          <Button
            onClick={handleNext}
            className="px-6 bg-[#028A75] hover:bg-[#026B5C]"
          >
            {isLastStep ? "Complete Package" : "Next Step"}
          </Button>
        </CardFooter>
      </Card>
    </div>
  );
}

// Add CSS to hide scrollbar
const style = `
  .scrollbar-hide::-webkit-scrollbar {
    display: none;
  }
`;

if (typeof document !== "undefined") {
  const styleSheet = document.createElement("style");
  styleSheet.innerText = style;
  document.head.appendChild(styleSheet);
}

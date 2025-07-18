import React from "react";
import {
  PieChart,
  Pie,
  Cell,
  ResponsiveContainer,
  Legend,
  Tooltip,
} from "recharts";
import { AlertTriangle, Lock, CheckCircle, DollarSign } from "lucide-react";

interface Component {
  name: string;
  price: number;
}

interface Venue {
  venue_id: number;
  venue_title: string;
  total_price: number;
}

interface BudgetBreakdownProps {
  packagePrice: number;
  selectedVenue: Venue | null;
  components: Component[];
  freebies: string[];
  isPackageLocked?: boolean;
  originalPrice?: number;
  onOverageWarning?: (overage: number) => void;
}

const COLORS = [
  "#0088FE", // Venue
  "#00C49F", // Components
  "#FFBB28", // Remaining/Buffer
  "#FF8042", // Overage (red for warning)
  "#8884D8", // Component colors
  "#82CA9D",
  "#FFC658",
  "#FF7C7C",
  "#9F7AEA",
  "#68D391",
];

export const BudgetBreakdown: React.FC<BudgetBreakdownProps> = ({
  packagePrice,
  selectedVenue,
  components,
  freebies,
  isPackageLocked = false,
  originalPrice,
  onOverageWarning,
}) => {
  // Calculate total component cost
  const totalComponentCost = components.reduce(
    (sum, component) => sum + component.price,
    0
  );

  // Calculate remaining budget or overage
  const venueCost = selectedVenue?.total_price || 0;
  const totalInclusionsCost = totalComponentCost;
  const budgetDifference = packagePrice - totalInclusionsCost;

  // Determine budget status
  const isOverBudget = budgetDifference < 0;
  const isExactBudget = budgetDifference === 0;
  const bufferAmount = budgetDifference > 0 ? budgetDifference : 0;
  const overageAmount = budgetDifference < 0 ? Math.abs(budgetDifference) : 0;

  // Trigger overage warning callback
  React.useEffect(() => {
    if (isOverBudget && onOverageWarning) {
      onOverageWarning(overageAmount);
    }
  }, [isOverBudget, overageAmount, onOverageWarning]);

  // Prepare data for pie chart - separate venue from package components
  const chartData = [
    // Package components
    ...(components || []).map((component, index) => ({
      name: component.name,
      value: component.price,
      color: COLORS[(index + 1) % COLORS.length],
      category: "inclusion",
    })),
    // Buffer or overage
    ...(budgetDifference !== 0
      ? [
          {
            name: isOverBudget ? "Overage" : "Buffer/Margin",
            value: Math.abs(budgetDifference),
            color: isOverBudget ? "#FF4444" : "#28A745",
            category: isOverBudget ? "overage" : "buffer",
          },
        ]
      : []),
  ].filter((item) => item.value > 0);

  // Calculate margin percentage
  const marginPercentage =
    packagePrice > 0 ? (budgetDifference / packagePrice) * 100 : 0;

  // Custom legend component
  const renderCustomLegend = (props: any) => {
    const { payload } = props;
    const total = packagePrice; // Use package price as total, not sum of components

    return (
      <div className="flex flex-col gap-1 mt-4 max-h-[200px] overflow-y-auto">
        {payload.map((entry: any, index: number) => {
          const percentage = ((entry.payload.value / total) * 100).toFixed(1);
          const isOverageItem = entry.payload.category === "overage";
          const isBufferItem = entry.payload.category === "buffer";

          return (
            <div
              key={index}
              className={`flex items-center gap-2 text-xs ${
                isOverageItem
                  ? "text-red-600 font-medium"
                  : isBufferItem
                    ? "text-green-600 font-medium"
                    : "text-gray-600"
              }`}
            >
              <div
                className="w-2 h-2 rounded-full"
                style={{ backgroundColor: entry.color }}
              />
              <span className="flex-1">
                {entry.value} - ₱{entry.payload.value.toLocaleString()} (
                {percentage}%)
              </span>
              {isOverageItem && (
                <AlertTriangle className="w-3 h-3 text-red-500" />
              )}
              {isBufferItem && (
                <CheckCircle className="w-3 h-3 text-green-500" />
              )}
            </div>
          );
        })}
      </div>
    );
  };

  return (
    <div className="space-y-6">
      {/* Package Price Lock Status */}
      {isPackageLocked && (
        <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
          <div className="flex items-center gap-2">
            <Lock className="w-4 h-4 text-blue-600" />
            <span className="text-sm font-medium text-blue-800">
              Package Price is Locked
            </span>
          </div>
          <p className="text-xs text-blue-600 mt-1">
            This package price cannot be reduced. It can only be increased or
            remain the same.
            {originalPrice && originalPrice !== packagePrice && (
              <span className="block mt-1">
                Original price: ₱{originalPrice.toLocaleString()}
              </span>
            )}
          </p>
        </div>
      )}

      {/* Budget Status Alert */}
      {isOverBudget && (
        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
          <div className="flex items-center gap-2">
            <AlertTriangle className="w-4 h-4 text-red-600" />
            <span className="text-sm font-medium text-red-800">
              Budget Overage Warning
            </span>
          </div>
          <p className="text-sm text-red-700 mt-1">
            Inclusions exceed package price by ₱{overageAmount.toLocaleString()}
            . Consider removing inclusions or increasing the package price.
          </p>
        </div>
      )}

      {bufferAmount > 0 && (
        <div className="bg-green-50 border border-green-200 rounded-lg p-4">
          <div className="flex items-center gap-2">
            <DollarSign className="w-4 h-4 text-green-600" />
            <span className="text-sm font-medium text-green-800">
              Buffer Available
            </span>
          </div>
          <p className="text-sm text-green-700 mt-1">
            ₱{bufferAmount.toLocaleString()} available as coordinator
            margin/buffer ({marginPercentage.toFixed(1)}% of package price).
          </p>
        </div>
      )}

      <div className="bg-white rounded-lg shadow p-6">
        <h3 className="text-lg font-semibold mb-6">
          Fixed Package Budget Breakdown
        </h3>

        {/* Chart Section */}
        {chartData.length > 0 ? (
          <div className="h-[450px] mb-4 mt-8">
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie
                  data={chartData}
                  cx="50%"
                  cy="40%"
                  innerRadius={60}
                  outerRadius={90}
                  dataKey="value"
                >
                  {chartData.map((entry, index) => (
                    <Cell
                      key={`cell-${index}`}
                      fill={entry.color}
                      strokeWidth={0}
                    />
                  ))}
                </Pie>
                <Tooltip
                  formatter={(value: number, name: string, props: any) => [
                    `₱${(value || 0).toLocaleString()}`,
                    props.payload.category === "overage"
                      ? "Overage Amount"
                      : props.payload.category === "buffer"
                        ? "Buffer/Margin"
                        : "Inclusion Cost",
                  ]}
                  labelStyle={{ color: "#374151" }}
                  contentStyle={{
                    backgroundColor: "#ffffff",
                    border: "1px solid #e5e7eb",
                    borderRadius: "8px",
                    boxShadow: "0 4px 6px -1px rgba(0, 0, 0, 0.1)",
                  }}
                />
                <Legend
                  content={renderCustomLegend}
                  layout="horizontal"
                  align="center"
                  verticalAlign="bottom"
                />
              </PieChart>
            </ResponsiveContainer>
          </div>
        ) : (
          <div className="h-[200px] flex items-center justify-center text-gray-500">
            <p>No inclusions added yet</p>
          </div>
        )}

        {/* Budget Summary */}
        <div className="mt-6 space-y-3 border-t pt-6">
          <div className="flex justify-between items-center">
            <span className="text-gray-600 font-medium flex items-center gap-2">
              Fixed Package Price:
              {isPackageLocked && <Lock className="w-3 h-3 text-blue-500" />}
            </span>
            <span className="font-bold text-lg text-gray-900">
              ₱{(packagePrice || 0).toLocaleString()}
            </span>
          </div>

          <div className="flex justify-between items-center">
            <span className="text-gray-600">Total Inclusions Cost:</span>
            <span className="font-semibold text-blue-600">
              ₱{(totalInclusionsCost || 0).toLocaleString()}
            </span>
          </div>

          <div className="flex justify-between items-center py-2 border-t">
            <span className="text-gray-600 font-medium">
              {isOverBudget
                ? "Overage:"
                : bufferAmount > 0
                  ? "Buffer/Margin:"
                  : "Status:"}
            </span>
            <span
              className={`font-bold text-lg flex items-center gap-1 ${
                isOverBudget
                  ? "text-red-600"
                  : bufferAmount > 0
                    ? "text-green-600"
                    : "text-gray-600"
              }`}
            >
              {isOverBudget && <AlertTriangle className="w-4 h-4" />}
              {bufferAmount > 0 && <CheckCircle className="w-4 h-4" />}₱
              {Math.abs(budgetDifference || 0).toLocaleString()}
              {isExactBudget && " (Exact)"}
            </span>
          </div>

          {/* Additional Info */}
          {marginPercentage !== 0 && (
            <div className="text-xs text-gray-500 text-center pt-2 border-t">
              {isOverBudget
                ? `${Math.abs(marginPercentage).toFixed(1)}% over budget`
                : `${marginPercentage.toFixed(1)}% margin`}
            </div>
          )}
        </div>

        {/* Freebies Section */}
        {freebies && freebies.length > 0 && (
          <div className="mt-6 pt-6 border-t">
            <h4 className="font-semibold mb-3 text-gray-900 flex items-center">
              <span className="w-2 h-2 bg-yellow-400 rounded-full mr-2"></span>
              Freebies (No Cost Impact)
            </h4>
            <div className="grid grid-cols-1 gap-2">
              {freebies.map((freebie, index) => (
                <div
                  key={`freebie-display-${index}`}
                  className="flex items-center space-x-2 text-sm text-gray-600"
                >
                  <span className="w-1 h-1 bg-yellow-400 rounded-full"></span>
                  <span>{freebie}</span>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  );
};
